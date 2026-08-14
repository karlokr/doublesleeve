<?php
/**
 * Propagates product text entered in the default language to every other locale.
 *
 * The shop runs en-US and fr-CA. A product typed into the back office in English
 * leaves the French fields empty, and the French storefront then renders a blank
 * name or description depending on the field. That is unworkable the moment a
 * human is entering stock daily, and it is invisible to whoever typed it because
 * they never look at /qc/.
 *
 * The rules that matter more than the translation itself:
 *
 *   - NEVER overwrite a human translation. A target field is only filled when it
 *     is empty, or when it still exactly matches the source text it was copied
 *     from last time (i.e. it was auto-filled and the source has since changed).
 *     Anything a person edited is authoritative and survives every re-save.
 *   - link_rewrite is a URL, not prose. Changing it silently breaks every existing
 *     link and any SEO built on it, so it is generated once when empty and then
 *     left alone forever.
 *   - A translation failure must leave the source text in place, never write an
 *     empty string. A missing translation is a nuisance; a blank product name is
 *     a broken page.
 *   - Card titles are exempt, because nobody writes them. A card's title is
 *     composed from its own matched facts, so for any product with a card identity
 *     the name and link_rewrite are RE-DERIVED here rather than copied across - and
 *     a hand-edited card title deliberately does not survive.
 *
 * Provenance is tracked in cc_i18n_autofill so "was this auto-filled?" is a fact
 * rather than a guess.
 */
declare(strict_types=1);

if (!defined('_PS_VERSION_')) {
    exit;
}

class Cryptocards_i18n extends Module
{
    /** Product fields worth propagating. */
    private const FIELDS = [
        'name',
        'description',
        'description_short',
        'meta_title',
        'meta_description',
        'link_rewrite',
    ];

    /** Written once when empty, then never touched again - see class docblock. */
    private const WRITE_ONCE = ['link_rewrite'];

    /**
     * Fields that belong to the name derivation rather than to autofill.
     *
     * A card title is not prose anyone wrote - it is composed from the matched
     * card's own facts - so translating the English string would mean translating a
     * derived value. For a product with a card identity these are re-derived
     * instead, which also means a hand-edited card title does not survive the save.
     */
    private const DERIVED = ['name', 'link_rewrite'];

    /** Guards against the recursion caused by saving inside a save hook. */
    private static bool $running = false;

    public function __construct()
    {
        $this->name = 'cryptocards_i18n';
        $this->tab = 'administration';
        $this->version = '1.1.0';
        $this->author = 'DoubleSleeve';
        $this->need_instance = 0;
        $this->bootstrap = true;

        parent::__construct();

        $this->displayName = 'DoubleSleeve translation autofill';
        $this->description = 'Copies product text from the default language into every other enabled language.';
    }

    public function install(): bool
    {
        $this->createTable();

        return parent::install() && $this->registerHook('actionProductSave');
    }

    private function createTable(): void
    {
        Db::getInstance()->execute(
            'CREATE TABLE IF NOT EXISTS ' . _DB_PREFIX_ . 'i18n_autofill (
                id_product INT UNSIGNED NOT NULL,
                id_lang    INT UNSIGNED NOT NULL,
                field      VARCHAR(32) NOT NULL,
                source     TEXT NOT NULL,
                -- What we actually WROTE into the target. Comparing only the
                -- source was not enough: once the English changed, a French
                -- string a human had edited looked eligible for overwrite.
                written    TEXT NOT NULL,
                date_upd   DATETIME NOT NULL,
                PRIMARY KEY (id_product, id_lang, field)
            ) ENGINE=' . _MYSQL_ENGINE_ . ' DEFAULT CHARSET=utf8mb4'
        );
    }

    public function hookActionProductSave(array $params): void
    {
        // Product::save() inside this hook would re-enter it.
        if (self::$running) {
            return;
        }

        $productId = (int) ($params['id_product'] ?? 0);
        if ($productId <= 0) {
            return;
        }

        self::$running = true;
        try {
            $this->fill($productId);
        } catch (Throwable $e) {
            // A translation problem must never block saving a product.
            PrestaShopLogger::addLog(
                'cryptocards_i18n: ' . $e->getMessage(),
                2,
                null,
                'Product',
                $productId
            );
        } finally {
            self::$running = false;
        }
    }

    /**
     * Rewrites a card's title in every language from its stored identity.
     *
     *     <card name> — <set name> <collector number>
     *
     * The atoms come from cc_card_identity, which derive-names.php fills from the
     * TCGplayer match, so this needs no network and no species table of its own. The
     * set name is read live rather than baked in, so renaming a set fixes every card
     * in it on the next save.
     *
     * Returns false when the product is not a card, which is how sealed product,
     * accessories and anything unmatched stay on the normal autofill path.
     */
    private function deriveCardName(int $productId): bool
    {
        $db = Db::getInstance();

        $identity = $db->getRow(
            'SELECT number, id_category_set FROM ' . _DB_PREFIX_ . 'card_identity
              WHERE id_product = ' . $productId
        );
        if (!$identity) {
            return false;
        }

        $cardNames = [];
        foreach ($db->executeS(
            'SELECT id_lang, card_name FROM ' . _DB_PREFIX_ . 'card_identity_lang
              WHERE id_product = ' . $productId
        ) ?: [] as $row) {
            $cardNames[(int) $row['id_lang']] = (string) $row['card_name'];
        }
        if ($cardNames === []) {
            return false;
        }

        $setNames = [];
        foreach ($db->executeS(
            'SELECT id_lang, name FROM ' . _DB_PREFIX_ . 'category_lang
              WHERE id_category = ' . (int) $identity['id_category_set']
        ) ?: [] as $row) {
            $setNames[(int) $row['id_lang']] = (string) $row['name'];
        }

        $number = trim((string) $identity['number']);

        foreach (Language::getLanguages(false) as $language) {
            $langId = (int) $language['id_lang'];
            $cardName = trim($cardNames[$langId] ?? '');
            if ($cardName === '') {
                continue;
            }

            // Mirrors composeCardTitle() in provisioning/lib/cardname.php.
            $title = $cardName;
            if (trim($setNames[$langId] ?? '') !== '') {
                $title .= ' — ' . trim($setNames[$langId]);
            }
            if ($number !== '') {
                $title .= ' ' . $number;
            }

            $current = (string) $db->getValue(
                'SELECT name FROM ' . _DB_PREFIX_ . 'product_lang
                  WHERE id_product = ' . $productId . ' AND id_lang = ' . $langId
            );
            if ($current === $title) {
                continue;
            }

            $set = ['`name` = "' . pSQL($title, true) . '"'];

            /**
             * link_rewrite is filled only when empty, the same write-once rule
             * autofill uses. The title is ours to correct on every save; the URL is
             * not - rewriting it would break every existing link each time a set is
             * renamed. `make derive-names` regenerates slugs deliberately when that
             * is actually wanted.
             */
            $existingSlug = (string) $db->getValue(
                'SELECT link_rewrite FROM ' . _DB_PREFIX_ . 'product_lang
                  WHERE id_product = ' . $productId . ' AND id_lang = ' . $langId
            );
            if ($existingSlug === '') {
                $slug = trim((string) (Tools::str2url($title) ?: ''), '-');
                if ($slug !== '') {
                    $set[] = '`link_rewrite` = "' . pSQL($slug) . '"';
                }
            }

            $db->execute(
                'UPDATE ' . _DB_PREFIX_ . 'product_lang SET ' . implode(', ', $set) . '
                  WHERE id_product = ' . $productId . ' AND id_lang = ' . $langId
            );
        }

        return true;
    }

    private function fill(int $productId): void
    {
        $db = Db::getInstance();
        $sourceLang = (int) Configuration::get('PS_LANG_DEFAULT');

        $isCard = $this->deriveCardName($productId);

        $targets = [];
        foreach (Language::getLanguages(false) as $language) {
            if ((int) $language['id_lang'] !== $sourceLang) {
                $targets[(int) $language['id_lang']] = (string) $language['iso_code'];
            }
        }
        if ($targets === []) {
            return;
        }

        $source = $db->getRow(
            'SELECT * FROM ' . _DB_PREFIX_ . 'product_lang
              WHERE id_product = ' . $productId . ' AND id_lang = ' . $sourceLang
        );
        if (!$source) {
            return;
        }

        // What we auto-wrote last time, so a human edit is distinguishable.
        $previous = [];
        foreach ($db->executeS(
            'SELECT id_lang, field, source, written FROM ' . _DB_PREFIX_ . 'i18n_autofill
              WHERE id_product = ' . $productId
        ) ?: [] as $row) {
            $previous[(int) $row['id_lang']][(string) $row['field']] = [
                'source' => (string) $row['source'],
                'written' => (string) $row['written'],
            ];
        }

        foreach ($targets as $langId => $iso) {
            $current = $db->getRow(
                'SELECT * FROM ' . _DB_PREFIX_ . 'product_lang
                  WHERE id_product = ' . $productId . ' AND id_lang = ' . $langId
            );
            if (!$current) {
                continue;
            }

            $updates = [];
            foreach (self::FIELDS as $field) {
                if ($isCard && in_array($field, self::DERIVED, true)) {
                    continue;   // owned by the derivation, not by autofill
                }
                $sourceText = trim((string) ($source[$field] ?? ''));
                if ($sourceText === '') {
                    continue;
                }
                $existing = trim((string) ($current[$field] ?? ''));

                if (!$this->mayWrite($field, $existing, $previous[$langId][$field] ?? null, $sourceText)) {
                    continue;
                }

                $translated = $this->translate($sourceText, $iso, $field);
                if ($translated === '') {
                    continue;   // never blank a field
                }

                $updates[$field] = $translated;
            }

            if ($updates === []) {
                continue;
            }

            $set = [];
            foreach ($updates as $field => $value) {
                $set[] = '`' . bqSQL($field) . '` = "' . pSQL($value, true) . '"';
            }
            $db->execute(
                'UPDATE ' . _DB_PREFIX_ . 'product_lang SET ' . implode(', ', $set) . '
                  WHERE id_product = ' . $productId . ' AND id_lang = ' . $langId
            );

            foreach ($updates as $field => $value) {
                $db->execute(
                    'INSERT INTO ' . _DB_PREFIX_ . 'i18n_autofill
                        (id_product, id_lang, field, source, written, date_upd)
                     VALUES (' . $productId . ', ' . $langId . ', "' . pSQL($field) . '",
                             "' . pSQL(trim((string) $source[$field]), true) . '",
                             "' . pSQL($value, true) . '", NOW())
                     ON DUPLICATE KEY UPDATE source = VALUES(source),
                                             written = VALUES(written), date_upd = NOW()'
                );
            }
        }
    }

    /**
     * May this field be written?
     *
     * Empty                        -> yes, nothing to lose.
     * Still exactly what we wrote,
     *   and the source has changed  -> yes, refresh it.
     * Still exactly what we wrote,
     *   source unchanged            -> no, nothing to do.
     * Anything else                 -> NO. Someone edited it by hand.
     *
     * The last case is the rule that matters, and the reason `written` is
     * recorded alongside `source`: comparing sources alone marked a
     * human-translated field as stale the moment the English text changed, and
     * cheerfully overwrote it.
     *
     * @param array{source:string, written:string}|null $previous
     */
    private function mayWrite(string $field, string $existing, ?array $previous, string $sourceText): bool
    {
        if ($existing === '') {
            return true;
        }
        if (in_array($field, self::WRITE_ONCE, true)) {
            return false;   // present and write-once: never touch it again
        }
        if ($previous === null) {
            return false;   // we did not write it, so a person did
        }
        if ($existing !== $previous['written']) {
            return false;   // a person has edited what we wrote - it is theirs now
        }

        return $previous['source'] !== $sourceText;
    }

    /**
     * Source text -> target language.
     *
     * Deliberately a seam, not a service. Card copy on this shop is templated
     * from structured data, so the honest default is to leave text as-is rather
     * than machine-translate prose badly and call it French. Point this at a
     * provider when there is real free text worth translating; returning the
     * source unchanged is a safe, visible no-op in the meantime.
     */
    private function translate(string $text, string $targetIso, string $field): string
    {
        if ($field === 'link_rewrite') {
            return (string) (Tools::str2url($text) ?: '');
        }

        return Hook::exec('actionCryptocardsTranslate', [
            'text' => $text,
            'iso' => $targetIso,
            'field' => $field,
        ]) ?: $text;
    }
}
