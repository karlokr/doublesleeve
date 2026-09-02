<?php
/**
 * Meilisearch-backed instant search for the storefront search box.
 *
 * The module only adds the front-end behaviour; queries are proxied through this
 * shop's own front controller so the Meilisearch master key stays server-side and
 * is never exposed to the browser.
 */
declare(strict_types=1);

if (!defined('_PS_VERSION_')) {
    exit;
}

class Cryptocards_search extends Module
{
    public function __construct()
    {
        $this->name = 'cryptocards_search';
        $this->tab = 'search_filter';
        $this->version = '1.0.0';
        $this->author = 'DoubleSleeve';
        $this->need_instance = 0;
        $this->bootstrap = true;

        parent::__construct();

        $this->displayName = $this->trans('DoubleSleeve instant search', [], 'Modules.Cryptocardssearch.Admin');
        $this->description = $this->trans(
            'Typo-tolerant instant search powered by Meilisearch.',
            [],
            'Modules.Cryptocardssearch.Admin'
        );
    }

    public function install(): bool
    {
        return parent::install() && $this->registerHook('displayHeader');
    }

    public function hookDisplayHeader(): void
    {
        Media::addJsDef([
            'cryptocardsSearchUrl' => $this->context->link->getModuleLink(
                'cryptocards_search',
                'ajax',
                [],
                true
            ),
        ]);

        $this->context->controller->registerStylesheet(
            'cryptocards-search',
            'modules/' . $this->name . '/views/css/instantsearch.css',
            ['media' => 'all', 'priority' => 200]
        );
        $this->context->controller->registerJavascript(
            'cryptocards-search',
            'modules/' . $this->name . '/views/js/instantsearch.js',
            ['position' => 'bottom', 'priority' => 200]
        );
    }
}
