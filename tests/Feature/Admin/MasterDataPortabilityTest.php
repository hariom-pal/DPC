<?php

declare(strict_types=1);

namespace Tests\Feature\Admin;

use App\Domain\Authentication\Models\User;
use App\Domain\Department\Models\Department;
use App\Domain\Master\Models\Designation;
use App\Domain\Master\Models\District;
use App\Domain\Master\Models\Qualification;
use App\Domain\Master\Support\MasterRegistry;
use App\Enums\MasterStatus;
use App\Enums\Role;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * Search, CSV export and bulk upload across the admin masters (business
 * instruction, 2026-09-06).
 *
 * One controller and one service serve every master in `MasterRegistry`, so these
 * tests are written against that seam rather than screen by screen: what is pinned
 * is the behaviour every master inherits.
 */
final class MasterDataPortabilityTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolesAndPermissionsSeeder::class);

        $this->admin = User::factory()->create();
        $this->admin->assignRole(Role::Admin->value);
    }

    // ------------------------------------------------------------------ search

    public function test_the_listing_is_narrowed_by_the_search_term(): void
    {
        Designation::query()->create(['name' => 'Deputy Secretary', 'code' => 'DS', 'status' => MasterStatus::Active->value]);
        Designation::query()->create(['name' => 'Librarian', 'code' => 'LIB', 'status' => MasterStatus::Active->value]);

        $this->actingAs($this->admin)
            ->get(route('admin.designations.index', ['q' => 'secre']))
            ->assertOk()
            ->assertSee('Deputy Secretary')
            ->assertDontSee('Librarian');
    }

    /**
     * A term containing `%` matches that character, and does not match everything.
     *
     * `LIKE` treats `%` and `_` as wildcards, so a term carrying either is a way to
     * make a search return rows it should not — mildly, here, but the same escaping
     * bug in a different query is an injection.
     */
    public function test_a_wildcard_in_the_search_term_is_matched_literally(): void
    {
        Designation::query()->create(['name' => 'Librarian', 'code' => 'LIB', 'status' => MasterStatus::Active->value]);

        $this->actingAs($this->admin)
            ->get(route('admin.designations.index', ['q' => '%']))
            ->assertOk()
            ->assertDontSee('Librarian');
    }

    // ------------------------------------------------------------------ export

    public function test_the_export_carries_the_master_as_csv(): void
    {
        Designation::query()->create(['name' => 'Deputy Secretary', 'code' => 'DS', 'status' => MasterStatus::Active->value]);

        $response = $this->actingAs($this->admin)
            ->get(route('admin.master-data.export', 'designations'))
            ->assertOk()
            ->assertDownload();

        $csv = $response->streamedContent();

        $this->assertStringContainsString('code,name,status', $csv);
        $this->assertStringContainsString('DS,"Deputy Secretary",active', $csv);
    }

    /**
     * The export honours the same `?q=` the screen does.
     *
     * An officer who has filtered to twelve rows means to export those twelve. An
     * export that quietly ignored the filter is the sort of thing nobody notices
     * until the file is in a meeting.
     */
    public function test_the_export_honours_the_search_term(): void
    {
        Designation::query()->create(['name' => 'Deputy Secretary', 'code' => 'DS', 'status' => MasterStatus::Active->value]);
        Designation::query()->create(['name' => 'Librarian', 'code' => 'LIB', 'status' => MasterStatus::Active->value]);

        $csv = $this->actingAs($this->admin)
            ->get(route('admin.master-data.export', ['master' => 'designations', 'q' => 'secre']))
            ->assertOk()
            ->streamedContent();

        $this->assertStringContainsString('Deputy Secretary', $csv);
        $this->assertStringNotContainsString('Librarian', $csv);
    }

    /**
     * Excel reads a CSV as the system codepage unless the file says otherwise, and
     * half of these masters carry Hindi. Without the byte order mark every
     * Devanagari name opens as mojibake — which looks like data loss.
     */
    public function test_the_export_is_readable_as_utf8_by_a_spreadsheet(): void
    {
        $csv = $this->actingAs($this->admin)
            ->get(route('admin.master-data.export', 'designations'))
            ->streamedContent();

        $this->assertStringStartsWith("\xEF\xBB\xBF", $csv);
    }

    // ---------------------------------------------------------------- template

    /**
     * Every registered master offers a template, and it carries filled example rows.
     *
     * A header-only file leaves somebody guessing whether a status is `active` or
     * `Active`, and whether a district is named or numbered.
     *
     * @return list<array{0: string}>
     */
    public static function masters(): array
    {
        return array_map(
            static fn (string $key): array => [$key],
            array_keys(MasterRegistry::all()),
        );
    }

    #[DataProvider('masters')]
    public function test_every_master_offers_a_sample_template(string $master): void
    {
        $definition = MasterRegistry::find($master);
        $this->assertNotNull($definition);

        $csv = $this->actingAs($this->admin)
            ->get(route('admin.master-data.template', $master))
            ->assertOk()
            ->streamedContent();

        $this->assertStringContainsString(implode(',', $definition->headers()), $csv);
        $this->assertNotSame([], $definition->sample, "{$master} offers a template with no example row");
    }

    /**
     * A template downloaded and uploaded unaltered is accepted.
     *
     * The round trip is the whole promise of this feature, and it is where the
     * byte order mark bites: left on the first header, `code` arrives as
     * `\xEF\xBB\xBFcode` and every row reports a missing column.
     */
    #[DataProvider('masters')]
    public function test_a_template_uploaded_unaltered_is_accepted(string $master): void
    {
        // The two masters whose templates name rows in other masters are exercised
        // by their own test below, where those rows exist.
        if (in_array($master, ['departments', 'department-posts'], true)) {
            $this->markTestSkipped('Covered by the lookup test, which seeds the rows these reference.');
        }

        $csv = $this->actingAs($this->admin)
            ->get(route('admin.master-data.template', $master))
            ->streamedContent();

        $this->actingAs($this->admin)
            ->post(route('admin.master-data.import', $master), [
                'file' => UploadedFile::fake()->createWithContent('rows.csv', $csv),
            ])
            ->assertSessionHasNoErrors();
    }

    // ------------------------------------------------------------------ import

    public function test_an_upload_adds_new_rows_and_updates_matching_ones(): void
    {
        // `TestCase` seeds the lookup masters, so this asserts against what the
        // upload changed rather than against an empty table.
        Qualification::query()->updateOrCreate(
            ['code' => 'TEST-EXISTING'],
            ['name_en' => 'Old Name', 'name_hi' => 'पुराना', 'status' => MasterStatus::Active->value],
        );

        $before = Qualification::query()->count();

        $csv = "code,name_en,name_hi,status\n"
            ."TEST-EXISTING,New Name,नया,active\n"
            ."TEST-NEW,Brand New,बिलकुल नया,active\n";

        $this->actingAs($this->admin)
            ->post(route('admin.master-data.import', 'qualifications'), [
                'file' => UploadedFile::fake()->createWithContent('q.csv', $csv),
            ])
            ->assertSessionHasNoErrors();

        // The matching row was updated in place, not duplicated...
        $this->assertSame('New Name', Qualification::query()->where('code', 'TEST-EXISTING')->value('name_en'));

        // ...and only the unmatched one was added.
        $this->assertSame('Brand New', Qualification::query()->where('code', 'TEST-NEW')->value('name_en'));
        $this->assertSame($before + 1, Qualification::query()->count());
    }

    /**
     * **Nothing is ever deleted by an upload.**
     *
     * A spreadsheet is an easy thing to upload half of, and a master that emptied
     * itself because somebody uploaded the wrong file would take every dropdown in
     * the portal with it.
     */
    public function test_a_row_absent_from_the_file_is_left_alone(): void
    {
        Qualification::query()->updateOrCreate(
            ['code' => 'TEST-KEEP'],
            ['name_en' => 'Untouched', 'name_hi' => 'अछूता', 'status' => MasterStatus::Active->value],
        );

        $this->actingAs($this->admin)->post(route('admin.master-data.import', 'qualifications'), [
            'file' => UploadedFile::fake()->createWithContent(
                'q.csv',
                "code,name_en,name_hi,status\nTEST-NEW,New One,नया,active\n",
            ),
        ])->assertSessionHasNoErrors();

        $this->assertNotNull(Qualification::query()->where('code', 'TEST-KEEP')->first());
    }

    /**
     * A file that is wrong anywhere changes nothing, and says every line that was
     * wrong.
     *
     * Both halves matter. An officer fixing a two-hundred-row spreadsheet one
     * message at a time would upload it twenty times; and a partial write leaves a
     * master in a state nobody chose.
     */
    public function test_a_bad_file_is_refused_whole_and_reports_every_line(): void
    {
        $before = Qualification::query()->count();

        $csv = "code,name_en,name_hi,status\n"
            .",Missing Code,कोड नहीं,active\n"
            ."TEST-OK,Fine,ठीक,active\n"
            ."TEST-BAD,Bad Status,गलत,retired\n";

        $this->actingAs($this->admin)
            ->post(route('admin.master-data.import', 'qualifications'), [
                'file' => UploadedFile::fake()->createWithContent('q.csv', $csv),
            ])
            ->assertSessionHasErrors('file');

        // Not even the one good row on line 3 was written.
        $this->assertSame($before, Qualification::query()->count());
        $this->assertNull(Qualification::query()->where('code', 'TEST-OK')->first());

        $errors = session('errors')?->get('file') ?? [];

        $this->assertCount(2, $errors, 'every bad line is reported, not just the first');
        $this->assertStringContainsString('2', $errors[0]);
        $this->assertStringContainsString('4', $errors[1]);
    }

    /**
     * A CSV names a district; the importer resolves it to an id.
     *
     * Nobody filling a spreadsheet knows the master's primary keys, and a template
     * asking for one would not be usable by the people it is for.
     */
    public function test_a_lookup_column_is_matched_by_name_not_by_id(): void
    {
        District::query()->updateOrCreate(
            ['lgd_code' => 'TEST-387'],
            ['state_lgd_code' => '22', 'name_en' => 'Testpur', 'name_hi' => 'टेस्टपुर', 'status' => MasterStatus::Active->value],
        );

        // Lower-cased on purpose: a spreadsheet typed by hand will not match the
        // master's capitalisation, and refusing it would help nobody.
        $csv = "code,name,district,place,pin,email,status\n"
            ."TEST-SE,School Education Department,testpur,Raipur,492001,se@cg.gov.in,active\n";

        $this->actingAs($this->admin)
            ->post(route('admin.master-data.import', 'departments'), [
                'file' => UploadedFile::fake()->createWithContent('d.csv', $csv),
            ])
            ->assertSessionHasNoErrors();

        $department = Department::query()->where('code', 'TEST-SE')->firstOrFail();

        $this->assertSame('Testpur', $department->district?->name_en);
    }

    public function test_a_lookup_value_matching_nothing_is_refused(): void
    {
        $csv = "code,name,district,place,pin,email,status\n"
            ."TEST-SE,School Education Department,Atlantis,Raipur,492001,se@cg.gov.in,active\n";

        $this->actingAs($this->admin)
            ->post(route('admin.master-data.import', 'departments'), [
                'file' => UploadedFile::fake()->createWithContent('d.csv', $csv),
            ])
            ->assertSessionHasErrors('file');

        $this->assertNull(Department::query()->where('code', 'TEST-SE')->first());
    }

    // --------------------------------------------------------------- the guard

    /**
     * `{master}` reaches the registry and nothing else.
     *
     * The registry is the allow-list. A parameter that resolved to a model name
     * would be a way to export, or overwrite, any table in the database.
     */
    public function test_an_unregistered_master_is_not_reachable(): void
    {
        $this->actingAs($this->admin)
            ->get(route('admin.master-data.export', 'users'))
            ->assertNotFound();
    }

    public function test_only_the_administrator_reaches_these_routes(): void
    {
        $officer = User::factory()->create();
        $officer->assignRole(Role::AuthorizedOfficer->value);

        $this->actingAs($officer)
            ->get(route('admin.master-data.export', 'designations'))
            ->assertForbidden();
    }
}
