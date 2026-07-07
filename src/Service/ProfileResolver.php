<?php declare(strict_types=1);

namespace Topdata\TopdataBetterSearchSW6\Service;

use Symfony\Component\HttpFoundation\Request;

class ProfileResolver
{
    public function __construct(private readonly ProfileRegistry $profileRegistry)
    {
    }

    public function resolveActiveProfile(Request $request): string
    {
        // 1. Query override (highest priority for debugging/CLI testing)
        $override = $request->query->get('_search_profile');
        if (\is_string($override) && $this->profileRegistry->getProfile($override) !== null) {
            $request->attributes->set('tdbs_assigned_profile', $override);
            return $override;
        }

        // 2. Read existing assigned cookie
        $cookieProfile = $request->cookies->get('tdbs_profile');
        if (\is_string($cookieProfile) && $this->profileRegistry->getProfile($cookieProfile) !== null) {
            $request->attributes->set('tdbs_assigned_profile', $cookieProfile);
            return $cookieProfile;
        }

        // 3. Roll distribution bucket
        $globalConfig = $this->profileRegistry->getGlobalConfig();
        $abEnabled = $globalConfig['ab_testing']['enabled'] ?? false;
        $distribution = $globalConfig['ab_testing']['distribution'] ?? [];

        if ($abEnabled && !empty($distribution)) {
            $assigned = $this->rollBucket($distribution);
            if ($assigned !== null) {
                $request->attributes->set('tdbs_assigned_profile', $assigned);
                return $assigned;
            }
        }

        // 4. Default Fallback
        $profiles = $this->profileRegistry->getActiveProfiles();
        $keys = array_keys($profiles);
        $default = !empty($keys) ? $keys[0] : 'default';

        $request->attributes->set('tdbs_assigned_profile', $default);
        return $default;
    }

    /**
     * @param array<string, int> $distribution
     */
    private function rollBucket(array $distribution): ?string
    {
        $totalWeight = array_sum($distribution);
        if ($totalWeight <= 0) {
            return null;
        }

        $roll = random_int(1, $totalWeight);
        $current = 0;

        foreach ($distribution as $profileId => $weight) {
            $current += $weight;
            if ($roll <= $current) {
                return $profileId;
            }
        }

        return null;
    }
}
