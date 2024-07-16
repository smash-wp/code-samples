<?php

namespace GDPR;

use App\CompanyProfile;
use App\Models\GDPR\GdprRequest;
use App\Services\AuthService;
use App\Services\CompanyProfileAccessService;
use App\Services\CompanyProfilesService;
use App\Services\UserService;
use App\User;
use Carbon\Carbon;
use Illuminate\Support\Facades\Log;
use function App\Services\abort;
use function App\Services\config;
use function App\Services\now;

class GDPRService
{
    /** @var UserService */
    private $userService;
    /** @var CompanyProfilesService */
    private $companyProfilesService;

    public function __construct(UserService $userService, CompanyProfilesService $companyProfilesService)
    {
        $this->userService = $userService;
        $this->companyProfilesService = $companyProfilesService;
    }

    /**
     * @param string $token
     * @return GdprRequest
     * @throws \Exception
     */
    public function confirmGdprRequest(string $token): GdprRequest
    {
        $gdprRequest = GdprRequest::whereToken($token)->first();
        if (empty($gdprRequest) || Carbon::parse($gdprRequest->token_expired)->lt(now())) {
            throw new \InvalidArgumentException('token expired');
        }

        if (!empty($gdprRequest->companyProfile)) {
            $accessType = $gdprRequest->type === GdprRequest::TYPE_DELETION
                ? CompanyProfileAccessService::PERMISSIONS_CLOSE_COMPANY_PROFILE
                : CompanyProfileAccessService::PERMISSIONS_DEACTIVATE_COMPANY_PROFILE;

            $hasAccess = CompanyProfileAccessService::hasPermissionForCompanyProfile(
                $gdprRequest->user,
                $gdprRequest->companyProfile,
                $accessType
            );

            if (!$hasAccess) {
                abort(403);
            }
        }

        AuthService::authUserIfNotAlready($gdprRequest->user, $gdprRequest->companyProfile);

        $this->setBlockApplicant($gdprRequest->user, $gdprRequest->companyProfile);

        $gdprRequest->confirm()->save();
        $gdprRequest->refresh();

        return $gdprRequest;
    }

    /**
     * @param string $token
     * @return void
     * @throws \Exception
     */
    public function cancelGdprRequest(string $token): void
    {
        $gdprRequest = GdprRequest::whereCancelToken($token)->first();
        if (empty($gdprRequest) || Carbon::parse($gdprRequest->cancel_token_expired)->lt(now())) {
            throw new \InvalidArgumentException('token expired');
        }

        AuthService::authUserIfNotAlready($gdprRequest->user, $gdprRequest->companyProfile);

        $this->setBlockApplicant($gdprRequest->user, $gdprRequest->companyProfile, false);

        $gdprRequest->delete();
    }

    public function setBlockApplicant(User $user, ?CompanyProfile $companyProfile = null, $block = true)
    {
        if (!empty($companyProfile)) {
            $this->companyProfilesService->setCompanyProfileIsBlocked($companyProfile, $block);
        } else {
            $this->userService->setUserIsBlocked($user, $block);
        }
    }

    /**
     * @param string $requestType
     * @param User $user
     * @param array $data
     * @return void
     * @throws \Exception
     */
    public static function createOrUpdateGdprRequestForUser(int $type, User $user, array $data): void
    {
        $data['type'] = $type;

        if ($type === GdprRequest::TYPE_DELETION) {
            !empty($user->gdprDeletionRequest)
                ? $user->gdprDeletionRequest()->update($data) : $user->gdprDeletionRequest()->create($data);
        } elseif ($type === GdprRequest::TYPE_FORGOTTEN) {
            !empty($user->gdprForgottenRequest)
                ? $user->gdprForgottenRequest()->update($data) : $user->gdprForgottenRequest()->create($data);
        } else {
            throw new \Exception('Unsupported GDPR request type');
        }
    }

    /**
     * @param string $requestType
     * @param CompanyProfile $companyProfile
     * @param array $data
     * @return void
     * @throws \Exception
     */
    public static function createOrUpdateGdprRequestForCompanyProfile(int $type, CompanyProfile $companyProfile, User $user, array $data): void
    {
        $data['type'] = $type;
        $data['user_id'] = $user->id;

        if ($type === GdprRequest::TYPE_DELETION) {
            !empty($companyProfile->gdprDeletionRequest)
                ? $companyProfile->gdprDeletionRequest()->update($data) : $companyProfile->gdprDeletionRequest()->create($data);
        } elseif ($type === GdprRequest::TYPE_FORGOTTEN) {
            !empty($companyProfile->gdprForgottenRequest)
                ? $companyProfile->gdprForgottenRequest()->update($data) : $companyProfile->gdprForgottenRequest()->create($data);
        } else {
            throw new \Exception('Unsupported GDPR request type');
        }
    }

    public function checkIfBlockedForApply(int $type, User $user, ?CompanyProfile $companyProfile = null): bool
    {
        if ($type === GdprRequest::TYPE_FORGOTTEN) {
            return false;
        }

        // if don't have any transactions or investments — let them do whatever they want
        if (!empty($companyProfile)) {
            if (!$companyProfile->have_transactions) {
                return false;
            }
        } elseif (!$user->have_transactions) {
            return false;
        }

        // if we got here, then it means that transactions exist
        if ($type === GdprRequest::TYPE_DELETION) {
            $gdprRequest = !empty($companyProfile) ? $companyProfile->gdprDeletionRequest : $user->gdprDeletionRequest;
        } else {
            $gdprRequest = !empty($companyProfile) ? $companyProfile->gdprForgottenRequest : $user->gdprForgottenRequest;
        }

        // if user/company profile can apply GDPR request again — will return false (that is not blocked)
        // else will true — that is blocked
        if (!empty($gdprRequest)) {
            return !$gdprRequest->can_be_requested_again;
        }

        // if have transactions, and GDPR request wasn't created before, then create it
        $requestData = [
            'can_be_requested_after' => now()->addYears(config('app.gdpr_transactions_store_years')),
            'type' => $type
        ];

        if (!empty($companyProfile)) {
            self::createOrUpdateGdprRequestForCompanyProfile($type, $companyProfile, $user, $requestData);
        } else {
            self::createOrUpdateGdprRequestForUser($type, $user, $requestData);
        }

        return true;
    }

    public function deleteUserIfPossibleOrFreeze(User $user)
    {
        // if user have transactions and we cannot delete it right now
        // then we have to schedule a deletion after 5 years from now (number of years is configurable)
        if (!empty($user->gdprDeletionRequest->can_be_requested_after)) {
            $user->gdprDeletionRequest->can_be_requested_after = null;
            $user->gdprDeletionRequest->execute_after = now()->addYears(config('app.gdpr_transactions_store_additional_years'));
            $user->gdprDeletionRequest->save();

            $user->freeze();

            Log::info(sprintf("GDPR Delete: user account (ID = %s) has been frozen", $user->id));
        } else {
            $this->userService->deleteUserCompletely($user);
        }
    }

    public function deleteCompanyProfileIfPossibleOrFreeze(CompanyProfile $companyProfile)
    {
        // if company profile have transactions and we cannot delete it right now
        // then we have to do the same as for the user above — freeze and postpone deletion
        if (!empty($companyProfile->gdprDeletionRequest->can_be_requested_after)) {
            $companyProfile->gdprDeletionRequest->can_be_requested_after = null;
            $companyProfile->gdprDeletionRequest->execute_after = now()->addYears(config('app.gdpr_transactions_store_additional_years'));
            $companyProfile->gdprDeletionRequest->save();

            $companyProfile->freeze();

            Log::info(sprintf("GDPR Delete: company profile account (ID = %s) has been frozen", $companyProfile->id));
        } else {
            $this->companyProfilesService->removeCompanyProfileCompletely($companyProfile);
        }
    }
}
