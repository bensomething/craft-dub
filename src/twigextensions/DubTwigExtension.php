<?php

namespace bensomething\craftdub\twigextensions;

use bensomething\craftdub\Plugin;
use craft\elements\Entry;
use Twig\Extension\AbstractExtension;
use Twig\TwigFunction;

class DubTwigExtension extends AbstractExtension
{
    public function getFunctions(): array
    {
        return [
            new TwigFunction('dubLink', [$this, 'getLink']),
            new TwigFunction('dubQr', [$this, 'getQr']),
        ];
    }

    public function getLink(Entry $entry): ?string
    {
        return Plugin::getInstance()->dub->getShortLink($entry->getCanonicalId(), $entry->siteId);
    }

    public function getQr(Entry $entry): ?string
    {
        return Plugin::getInstance()->dub->getQrUrl($entry->getCanonicalId(), $entry->siteId);
    }
}
