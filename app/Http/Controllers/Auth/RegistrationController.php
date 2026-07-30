<?php

declare(strict_types=1);

namespace App\Http\Controllers\Auth;

use App\Domain\Authentication\Requests\RegisterDepartmentRequest;
use App\Domain\Authentication\Services\RegistrationService;
use App\Domain\Authentication\Services\VerificationService;
use App\Domain\Department\Models\Department;
use App\Enums\DepartmentStatus;
use App\Enums\OtpPurpose;
use App\Http\Controllers\Controller;
use Illuminate\Contracts\View\View;
use Illuminate\Http\RedirectResponse;

class RegistrationController extends Controller
{
    public function __construct(
        private readonly RegistrationService $registration,
        private readonly VerificationService $verification,
    ) {}

    public function create(): View
    {
        $departments = Department::query()
            ->where('status', DepartmentStatus::Unregistered->value)
            ->orderBy('name')
            ->get();

        return view('auth.register', ['departments' => $departments]);
    }

    public function store(RegisterDepartmentRequest $request): RedirectResponse
    {
        /** @var array{department_id:int,district:string,place:string,pin:string,department_email:string,ao_name:string,ao_designation:string,ao_mobile:string,ao_email:string,ah_name:string,ah_designation:string,ah_mobile:string,ah_email:string} $data */
        $data = $request->validated();

        $authorizedOfficer = $this->registration->register($data);
        $this->verification->start($authorizedOfficer, OtpPurpose::Registration);

        $request->session()->put('pending_verification', [
            'user_id' => $authorizedOfficer->id,
            'purpose' => OtpPurpose::Registration->value,
        ]);

        return redirect()
            ->route('verify.show')
            ->with('status', 'Registration submitted. Credentials have been sent. Please verify the OTPs sent to your department email and Authorized Officer mobile.');
    }
}
