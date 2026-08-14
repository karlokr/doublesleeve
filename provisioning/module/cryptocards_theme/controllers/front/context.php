<?php
/**
 * Everything the product page knows about one product, as JSON.
 *
 * The product page gets this inline from displayHeader, because the id is in the
 * URL. The quick-view modal is not a product page: it renders the same markup
 * inside a LISTING, where none of those globals exist - so it was the one surface
 * still showing a bare Hummingbird product with no stock box, no badge line and no
 * copy picker, on a shop whose whole point is that you can pick your exact card.
 *
 * Same builders as the page uses, so the two can never describe a product
 * differently.
 */
declare(strict_types=1);

class Cryptocards_themeContextModuleFrontController extends ModuleFrontController
{
    /** @var bool */
    public $ajax = true;

    public function initContent(): void
    {
        header('Content-Type: application/json; charset=utf-8');

        $productId = (int) Tools::getValue('id_product');
        if ($productId <= 0) {
            $this->respond([]);
        }

        // Only for products a shopper can actually see - this endpoint is public,
        // and an inactive product's stock is nobody's business.
        $product = new Product($productId);
        if (!Validate::isLoadedObject($product) || !$product->active) {
            $this->respond([]);
        }

        $this->respond($this->module->productContext($productId));
    }

    private function respond(array $data): void
    {
        echo json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        exit;
    }
}
