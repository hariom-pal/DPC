<?php

declare(strict_types=1);

namespace Tests\Concerns;

use App\Domain\Dpc\Models\DpcProposal;
use App\Domain\PreDpc\Models\PreDpcProposal;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Testing\TestResponse;

/**
 * The Department Admin's eSigned steps (MoM of 2026-10-29) as a browser takes
 * them: the form, then the eSign simulator, signed in the signer's own name.
 */
trait SignsAsDepartmentAdmin
{
    /**
     * Allot or reassign over HTTP, with the assignment order, the declaration and
     * the eSign consent the form requires.
     *
     * @param  array<string, mixed>  $data
     */
    protected function signedAllot(PreDpcProposal|DpcProposal $proposal, array $data): TestResponse
    {
        Storage::fake((string) config('dpc.documents.disk', 'local'));

        $route = $proposal instanceof PreDpcProposal ? 'department.allotments.pre-dpc.update' : 'department.allotments.dpc.update';

        return $this->throughEsign($this->put(route($route, $proposal), $data + [
            'order' => UploadedFile::fake()->create('assignment-order.pdf', 20, 'application/pdf'),
            'declaration' => '1',
            'consent' => '1',
        ]));
    }

    /**
     * Release over HTTP, then eSign.
     */
    protected function signedRelease(mixed $officer, string $reason = 'transfer'): TestResponse
    {
        return $this->throughEsign($this->post(route('department.users.release', $officer), ['reason' => $reason, 'consent' => '1']));
    }

    /**
     * Completes the eSign simulator when the response leads to it; any other
     * response (a refusal, a validation error) is returned as it is.
     */
    protected function throughEsign(TestResponse $response, ?string $name = null): TestResponse
    {
        if (! $response->isRedirect() || ! str_contains((string) $response->headers->get('Location'), '/esign/simulator')) {
            return $response;
        }

        $pending = session('esign.pending');

        return $this->post(route('esign.simulator.submit'), [
            'reference' => is_array($pending) ? (string) $pending['reference'] : '',
            'decision' => 'sign',
            'name' => $name ?? (string) auth()->user()?->name,
        ]);
    }
}
