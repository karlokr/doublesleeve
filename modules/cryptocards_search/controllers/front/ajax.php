<?php
/**
 * JSON search endpoint backed by Meilisearch.
 *
 * Proxying rather than letting the browser hit Meilisearch directly keeps the
 * master key server-side. It also means the storefront keeps working (falling back
 * to an empty result set rather than a JS error) if the search container is down.
 */
declare(strict_types=1);

class Cryptocards_searchAjaxModuleFrontController extends ModuleFrontController
{
    public const MEILI_HOST = 'http://meilisearch:7700';

    /**
     * One index per storefront language - see search-index.php.
     *
     * A shared index meant a French shopper searched English documents: no hit for
     * "dracaufeu", and the hits they did get named their sets in English.
     */
    private function indexUid(): string
    {
        return 'catalog_' . strtolower((string) $this->context->language->iso_code);
    }

    /** @var bool */
    public $ajax = true;

    public function initContent(): void
    {
        header('Content-Type: application/json; charset=utf-8');

        $query = trim((string) Tools::getValue('q', ''));
        if ($query === '' || mb_strlen($query) < 2) {
            $this->respond(['hits' => [], 'query' => $query]);
        }

        $payload = json_encode([
            'q' => $query,
            'limit' => 8,
            'attributesToRetrieve' => ['doc_id', 'type', 'name', 'set_name', 'set_code',
                                       'series', 'rarity', 'price', 'url', 'image'],
        ], JSON_UNESCAPED_UNICODE);

        $curl = curl_init(self::MEILI_HOST . '/indexes/' . $this->indexUid() . '/search');
        curl_setopt_array($curl, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_POST => true,
            CURLOPT_TIMEOUT => 5,
            CURLOPT_POSTFIELDS => $payload,
            CURLOPT_HTTPHEADER => array_filter([
                'Content-Type: application/json',
                ($key = (string) getenv('MEILI_MASTER_KEY')) !== '' ? 'Authorization: Bearer ' . $key : null,
            ]),
        ]);
        $body = curl_exec($curl);
        $status = (int) curl_getinfo($curl, CURLINFO_HTTP_CODE);
        curl_close($curl);

        if ($body === false || $status >= 400) {
            $this->respond(['hits' => [], 'query' => $query, 'error' => 'search_unavailable']);
        }

        $decoded = json_decode((string) $body, true);
        $this->respond([
            'query' => $query,
            'hits' => $decoded['hits'] ?? [],
            'took' => $decoded['processingTimeMs'] ?? null,
        ]);
    }

    private function respond(array $data): void
    {
        echo json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        exit;
    }
}
