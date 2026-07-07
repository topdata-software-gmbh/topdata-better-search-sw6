<?php declare(strict_types=1);

namespace Topdata\TopdataBetterSearchSW6\Subscriber;

use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\HttpKernel\Event\ResponseEvent;
use Symfony\Component\HttpKernel\KernelEvents;
use Symfony\Component\HttpFoundation\Cookie;
use Topdata\TopdataBetterSearchSW6\Service\ProfileRegistry;

class CacheVariationSubscriber implements EventSubscriberInterface
{
    public function __construct(private readonly ProfileRegistry $profileRegistry)
    {
    }

    public static function getSubscribedEvents(): array
    {
        return [
            KernelEvents::RESPONSE => ['onResponse', -10],
        ];
    }

    public function onResponse(ResponseEvent $event): void
    {
        $request = $event->getRequest();
        $response = $event->getResponse();

        if (!$request->attributes->has('tdbs_assigned_profile')) {
            return;
        }

        $globalConfig = $this->profileRegistry->getGlobalConfig();
        $abEnabled = $globalConfig['ab_testing']['enabled'] ?? false;

        $profileId = (string) $request->attributes->get('tdbs_assigned_profile');

        // Always set the variation cookie (harmless when A/B is off —
        // it preserves the user's profile across sessions for future tests)
        $cookie = Cookie::create('tdbs_profile', $profileId, new \DateTime('+30 days'))
            ->withSameSite(Cookie::SAMESITE_LAX);
        $response->headers->setCookie($cookie);

        // Only instruct reverse proxies to vary on Cookie when A/B testing
        // is active, to avoid needlessly disabling the HTTP cache.
        if ($abEnabled) {
            $response->setVary('Cookie', false);
        }
    }
}
