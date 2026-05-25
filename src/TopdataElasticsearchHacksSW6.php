<?php declare(strict_types=1);

namespace Topdata\TopdataElasticsearchHacksSW6;

use Shopware\Core\Framework\Plugin;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Topdata\TopdataElasticsearchHacksSW6\DependencyInjection\ElasticsearchAnalysisCompilerPass;

class TopdataElasticsearchHacksSW6 extends Plugin
{
    public function build(ContainerBuilder $container): void
    {
        parent::build($container);

        $container->addCompilerPass(new ElasticsearchAnalysisCompilerPass());
    }
}
