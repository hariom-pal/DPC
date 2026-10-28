<?php

declare(strict_types=1);

namespace Tests\Feature\Identity;

use App\Domain\Authentication\Models\User;
use App\Domain\Authentication\Services\AccountSessions;
use App\Domain\Department\Models\Department;
use App\Domain\Identity\Models\IdentityCorrection;
use App\Domain\Identity\Models\IdentityVerification;
use App\Domain\Identity\Services\IdentityMatcher;
use App\Domain\Identity\Services\IdentityVerificationService;
use App\Enums\Role;
use App\Integrations\Contracts\DigiLockerProvider;
use App\Integrations\DigiLocker\DigiLockerOAuthProvider;
use App\Integrations\DigiLocker\DigiLockerUnavailable;
use Database\Seeders\DesignationSeeder;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;
use RuntimeException;
use Tests\TestCase;

/**
 * Officer identity verification, corrections, manual verification and the
 * mobile OTP (Phases 2–5, 2026-10-27; the mobile declaration withdrawn 2026-10-28).
 */
class IdentityVerificationTest extends TestCase
{
    use RefreshDatabase;

    private Department $department;

    private User $admin;

    private User $deptAdmin;

    private User $officer;

    private User $ao;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed([RolesAndPermissionsSeeder::class, DesignationSeeder::class]);
        Storage::fake((string) config('dpc.documents.disk', 'local'));

        $this->department = Department::query()->create(['code' => 'PHE', 'name' => 'Public Health Engineering Department', 'status' => 'registered']);
        $this->admin = User::factory()->create();
        $this->admin->assignRole(Role::Admin->value);
        $this->deptAdmin = User::factory()->assignedTo($this->department, Role::DepartmentAdmin)->create();

        // DigiLocker is the invited administrator's (2026-10-27): the officer
        // verified here is a Department Admin. An AO or AH is not verified.
        $this->officer = User::factory()->unverifiedIdentity()->assignedTo($this->department, Role::DepartmentAdmin)->create([
            'name' => 'Ramesh Kumar Verma', 'date_of_birth' => '1980-04-12', 'gender' => 'M', 'must_change_password' => false,
            'email' => 'ramesh.verma@cg.gov.in',
        ]);
        $this->ao = User::factory()->unverifiedIdentity()->assignedTo($this->department, Role::AuthorizedOfficer)->create([
            'name' => 'Ramesh Kumar Verma', 'date_of_birth' => '1980-04-12', 'gender' => 'M', 'must_change_password' => false,
            'email' => 'ramesh.verma.ao@cg.gov.in',
        ]);
    }

    private function signIn(?User $user = null): void
    {
        // Past the sign-in and DigiLocker rate limits between repeated attempts.
        $this->travel(2)->minutes();
        $user ??= $this->officer;
        $this->post(route('login.store'), ['login' => $user->email, 'password' => 'password'])->assertRedirect(route('identity.show'));
    }

    private function pdf(): UploadedFile
    {
        return UploadedFile::fake()->create('order.pdf', 20, 'application/pdf');
    }

    // ------------------------------------------------------- the DigiLocker step

    public function test_a_matching_digilocker_identity_verifies_and_leads_to_the_otp(): void
    {
        $this->signIn();

        // The OTP step cannot be reached before identity: it sends back to sign in.
        $this->get(route('verify.show'))->assertRedirect(route('login'));
        $this->signIn();

        // Salutation, case and full stops are ignored.
        $this->passIdentityAndDeclaration($this->officer, ['name' => 'SHRI ramesh  kumar. verma'])->assertRedirect(route('verify.show'));
        $this->passSignInChallenge()->assertRedirect(route('dashboard'));

        $officer = $this->officer->fresh();
        $this->assertNotNull($officer->identity_verified_at);
        $this->assertSame('digilocker_simulated', $officer->identity_verification_method);

        $row = IdentityVerification::query()->sole();
        $this->assertSame('verified', $row->outcome);
        $this->assertSame('M', $row->returned_identity['gender']);
        $this->assertStringNotContainsString('verma', mb_strtolower((string) DB::table('identity_verifications')->value('returned_identity')), 'Encrypted at rest.');
        $this->assertDatabaseHas('activity_timelines', ['event' => 'identity.verified', 'subject_id' => $officer->id]);
    }

    public function test_a_different_date_of_birth_gender_initials_or_script_is_a_mismatch(): void
    {
        foreach ([
            ['date_of_birth' => '1980-04-13'],
            ['gender' => 'F'],
            ['name' => 'R. K. Verma'],
            ['name' => 'रमेश कुमार वर्मा'],
            ['name' => 'Ramesh Kumar Varma'],
        ] as $as) {
            IdentityVerification::query()->toBase()->delete();
            $this->signIn();
            $this->passIdentityAndDeclaration($this->officer, $as)->assertRedirect(route('identity.show'));

            $this->assertNull($this->officer->fresh()->identity_verified_at, json_encode($as));
            $this->assertSame('mismatch', IdentityVerification::query()->latest('id')->value('outcome'));
        }

        $this->get(route('identity.show'))->assertSee('id="identity-mismatch"', false);
    }

    public function test_the_matcher_ignores_only_case_spaces_full_stops_and_salutations(): void
    {
        $this->assertSame('ramesh kumar verma', IdentityMatcher::normaliseName('Dr. RAMESH   Kumar Verma.'));
        $this->assertSame('sunita rathore', IdentityMatcher::normaliseName('Smt. Sunita Rathore'));
        $this->assertSame('रमेश वर्मा', IdentityMatcher::normaliseName('श्री रमेश वर्मा'));
        $this->assertNotSame(IdentityMatcher::normaliseName('R K Verma'), IdentityMatcher::normaliseName('Ramesh Kumar Verma'));
    }

    public function test_three_mismatches_in_24_hours_lock_and_technical_failures_do_not_count(): void
    {
        // Two technical failures (an unknown code) and a refused consent: never counted.
        foreach (range(1, 2) as $i) {
            $this->signIn();
            $start = $this->post(route('identity.start'));
            parse_str((string) parse_url((string) $start->headers->get('Location'), PHP_URL_QUERY), $q);
            $this->get(route('identity.callback', ['state' => $q['state'], 'code' => 'unknown-code']))
                ->assertRedirect(route('identity.show'))->assertSessionHasErrors(['identity' => __('identity.errors.technical')]);
        }
        $this->signIn();
        $start = $this->post(route('identity.start'));
        parse_str((string) parse_url((string) $start->headers->get('Location'), PHP_URL_QUERY), $q);
        $this->post(route('identity.simulator.submit'), ['state' => $q['state'], 'decision' => 'deny'])->assertRedirect();
        $this->get(route('identity.callback', ['state' => $q['state'], 'error' => 'access_denied']))->assertRedirect(route('identity.show'));

        foreach (range(1, 3) as $i) {
            $this->signIn();
            $this->passIdentityAndDeclaration($this->officer, ['gender' => 'F']);
        }

        $this->signIn();
        $this->get(route('identity.show'))->assertSee('id="identity-locked"', false);
        $this->post(route('identity.start'))->assertRedirect(route('identity.show'));
        $this->assertSame(3, IdentityVerification::query()->where('outcome', 'mismatch')->count());

        // A matching attempt after the window passes.
        $this->travel(25)->hours();
        $this->signIn();
        $this->passIdentityAndDeclaration($this->officer)->assertRedirect(route('verify.show'));
    }

    public function test_a_forged_or_replayed_callback_is_refused(): void
    {
        $this->signIn();
        $this->post(route('identity.start'));

        $this->get(route('identity.callback', ['state' => 'forged', 'code' => 'x']))
            ->assertRedirect(route('login'))->assertSessionHasErrors(['login' => __('identity.errors.state')]);
        $this->assertSame(0, IdentityVerification::query()->count());
    }

    public function test_an_account_that_is_not_aadhaar_verified_or_has_no_date_of_birth_is_not_verified(): void
    {
        $this->signIn();
        $this->passIdentityAndDeclaration($this->officer, ['aadhaar_verified' => '0'])->assertRedirect(route('identity.show'));
        $this->assertSame('not_aadhaar_verified', IdentityVerification::query()->latest('id')->value('outcome'));

        $this->officer->forceFill(['date_of_birth' => null])->save();
        $this->signIn();
        $this->passIdentityAndDeclaration($this->officer, ['date_of_birth' => '1980-04-12'])->assertRedirect(route('identity.show'));
        $this->assertSame('record_incomplete', IdentityVerification::query()->latest('id')->value('outcome'));
        $this->assertNull($this->officer->fresh()->identity_verified_at);
    }

    // ------------------------------------------------- corrections and locks

    public function test_the_super_admin_corrects_verified_details_with_a_document_which_revokes_and_ends_sessions(): void
    {
        $officer = $this->markIdentityVerified($this->officer);
        $this->actingAs($officer)->get(route('dashboard'))->assertOk();
        $transitionsBefore = DB::table('workflow_transitions')->count();

        $this->actingAs($this->admin)->post(route('admin.users.identity.correct', $officer), [
            'name' => 'Ramesh Kumar Varma', 'date_of_birth' => '1980-04-12', 'gender' => 'M', 'reason' => 'Aadhaar spelling',
        ])->assertSessionHasErrors(['identity' => __('identity.errors.document_required')]);

        $this->actingAs($this->admin)->post(route('admin.users.identity.correct', $officer), [
            'name' => 'Ramesh Kumar Varma', 'date_of_birth' => '1980-04-12', 'gender' => 'M', 'reason' => 'Aadhaar spelling', 'document' => $this->pdf(),
        ])->assertRedirect(route('admin.users.identity', $officer));

        $fresh = $officer->fresh();
        $this->assertSame('Ramesh Kumar Varma', $fresh->name);
        $this->assertNull($fresh->identity_verified_at, 'The verification is withdrawn.');
        $this->assertNotNull(IdentityVerification::query()->latest('id')->first()?->revoked_at ?? IdentityCorrection::query()->sole()->document_path);
        $this->assertSame('verified_correction', IdentityCorrection::query()->sole()->stage);
        $this->assertSame($transitionsBefore, DB::table('workflow_transitions')->count(), 'Past approvals are untouched.');

        // The officer's open session ends, and nothing is reachable until they verify again.
        $this->actingAs($officer)->withSession([
            AccountSessions::EPOCH_SESSION_KEY => 0,
        ])->get(route('dashboard'))->assertRedirect(route('login'));
        $this->assertDatabaseHas('security_events', ['user_id' => $officer->id, 'event' => 'sessions.revoked']);

        $this->actingAs($this->admin)->get(route('admin.users.identity', $officer))->assertOk()->assertSee('Aadhaar spelling');

        // Audit without personal data (Phase 9): the logs name the fields that
        // changed and who changed them, never the values.
        $this->assertDatabaseHas('security_events', ['user_id' => $officer->id, 'event' => 'identity.corrected', 'actor_id' => $this->admin->id]);
        $this->assertDatabaseHas('security_events', ['user_id' => $officer->id, 'event' => 'identity.revoked']);
        $logged = DB::table('security_events')->pluck('context')->implode(' ').' '.DB::table('activity_timelines')->pluck('properties')->implode(' ');
        foreach (['1980-04-12', 'Ramesh Kumar Varma', '1975-06-15'] as $value) {
            $this->assertStringNotContainsString($value, $logged);
        }
    }

    public function test_manual_verification_needs_a_reason_and_document_and_is_shown_as_such(): void
    {
        $this->actingAs($this->admin)->post(route('admin.users.identity.manual', $this->officer), ['reason' => 'No DigiLocker account'])
            ->assertSessionHasErrors('document');

        $this->actingAs($this->admin)->post(route('admin.users.identity.manual', $this->officer), ['reason' => 'No DigiLocker account', 'document' => $this->pdf()])
            ->assertRedirect(route('admin.users.identity', $this->officer));

        $officer = $this->officer->fresh();
        $this->assertSame('manual', $officer->identity_verification_method);
        $row = IdentityVerification::query()->sole();
        $this->assertSame(['manually_verified', $this->admin->id], [$row->outcome, $row->decided_by]);
        Storage::disk((string) config('dpc.documents.disk', 'local'))->assertExists((string) $row->document_path);
        $this->actingAs($this->admin)->get(route('admin.users.identity', $officer))->assertSee(__('identity.status.manually_verified'));

        // The same sign-in as a DigiLocker-verified officer: the mobile OTP.
        $this->post(route('login.store'), ['login' => $officer->email, 'password' => 'password'])->assertRedirect(route('verify.show'));
        $this->passSignInChallenge()->assertRedirect(route('dashboard'));
        $this->get(route('department.allotments.index'))->assertOk();
    }

    public function test_the_super_admin_can_force_re_verification_and_only_the_super_admin_decides(): void
    {
        $officer = $this->markIdentityVerified($this->officer);

        foreach ([$this->deptAdmin, $officer] as $notAdmin) {
            $this->actingAs($notAdmin)->get(route('admin.users.identity', $officer))->assertForbidden();
            $this->actingAs($notAdmin)->post(route('admin.users.identity.force', $officer), ['reason' => 'x'])->assertForbidden();
        }

        $this->actingAs($this->admin)->post(route('admin.users.identity.force', $officer), ['reason' => 'Security review'])
            ->assertRedirect(route('admin.users.identity', $officer));
        $this->assertNull($officer->fresh()->identity_verified_at);

        // Next sign-in goes back to DigiLocker.
        auth()->logout();
        $this->post(route('login.store'), ['login' => $officer->email, 'password' => 'password'])->assertRedirect(route('identity.show'));
    }

    // ----------------------------------------------------- the providers

    public function test_the_simulator_is_refused_in_production(): void
    {
        $this->app->detectEnvironment(fn (): string => 'production');

        $this->expectException(RuntimeException::class);
        app(DigiLockerProvider::class);
    }

    public function test_the_real_provider_reads_the_documented_response_and_refuses_anything_else(): void
    {
        $identity = DigiLockerOAuthProvider::parse(['name' => 'Ajit Kumar', 'dob' => '31121970', 'gender' => 'M', 'eaadhaar' => 'Y', 'digilockerid' => 'abc']);
        $this->assertSame(['Ajit Kumar', '1970-12-31', 'M', true], [$identity->name, $identity->dateOfBirth->toDateString(), $identity->gender, $identity->aadhaarVerified]);

        foreach ([['name' => 'A', 'dob' => '31-12-1970', 'gender' => 'M'], ['name' => '', 'dob' => '31121970', 'gender' => 'M'], ['name' => 'A', 'dob' => '31021970', 'gender' => 'M']] as $body) {
            try {
                DigiLockerOAuthProvider::parse($body);
                $this->fail('Refused: '.json_encode($body));
            } catch (DigiLockerUnavailable) {
                $this->addToAssertionCount(1);
            }
        }

        config(['features.digilocker' => true, 'dpc.digilocker.client_id' => null]);
        $this->expectException(DigiLockerUnavailable::class);
        app(DigiLockerProvider::class);
    }

    public function test_an_ao_or_ah_signs_in_without_digilocker(): void
    {
        $this->travel(2)->minutes();
        $this->post(route('login.store'), ['login' => $this->ao->email, 'password' => 'password'])->assertRedirect(route('verify.show'));
        $this->assertFalse(app(IdentityVerificationService::class)->requiredFor($this->ao));
    }

    public function test_no_aadhaar_number_document_or_photograph_is_stored(): void
    {
        foreach (['users', 'identity_verifications', 'identity_corrections', 'department_invitations'] as $table) {
            foreach (Schema::getColumnListing($table) as $column) {
                $this->assertDoesNotMatchRegularExpression('/aadhaar|^uid$|_uid$|photo/i', $column, "{$table}.{$column}");
            }
        }
    }
}
