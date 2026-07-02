<?php declare(strict_types=1);

namespace Topdata\TopdataBetterSearchSW6;

use Shopware\Core\Framework\Plugin;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Topdata\TopdataBetterSearchSW6\DependencyInjection\ElasticsearchAnalysisCompilerPass;

class TopdataBetterSearchSW6 extends Plugin
{
    public function build(ContainerBuilder $container): void
    {
        parent::build($container);

        $container->addCompilerPass(new ElasticsearchAnalysisCompilerPass());
    }
}
