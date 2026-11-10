<?php
/**
 * Quince
 *
 * @author    Quince Project <quinceproject@proton.me>
 * @copyright Since 2026 Marcin Gajewski
 * @license   https://opensource.org/licenses/OSL-3.0 Open Software License (OSL 3.0)
 */

declare(strict_types=1);

namespace Quince\FeatureRank\Rank;

use Db;

/**
 * Decyzje sklepu, które przebijają wyliczony ranking.
 *
 * Ranking jest statystyką i czasem się myli — albo raczej: liczy dobrze, a sklep
 * wie coś, czego w danych nie ma. „Norma emisji" wypełniona u połowy filtrów
 * wypada z listy, choć to pierwsza rzecz, o którą pyta klient warsztatu. I odwrotnie:
 * cecha poprawna statystycznie bywa dla sklepu nieistotna.
 *
 * Dwa stany, oba na jedną kategorię:
 *   PRZYPIETA  — pokaż zawsze, na początku listy, bez sprawdzania warunków,
 *   POMINIETA  — nie pokazuj nigdy, choćby wygrywała ranking.
 *
 * Decyzja jest per kategoria, nie globalna, bo ta sama cecha znaczy co innego
 * w różnych działach — dokładnie z tego powodu, dla którego w ogóle liczymy
 * ranking osobno dla każdej kategorii.
 */
final class Overrides
{
    public const TABELA = 'quince_feature_rank_override';

    public const PRZYPIETA = 'pin';
    public const POMINIETA = 'skip';

    /**
     * Stan każdej nadpisanej cechy w kategorii: id cechy => stan.
     *
     * @return array<int,string>
     */
    public function dlaKategorii(int $idCategory, int $idShop): array
    {
        $sql = sprintf(
            'SELECT id_feature, state FROM `%s` WHERE id_category = %d AND id_shop = %d',
            _DB_PREFIX_ . self::TABELA,
            $idCategory,
            $idShop
        );

        $wynik = Db::getInstance()->executeS($sql);
        $out = [];

        foreach (is_array($wynik) ? $wynik : [] as $w) {
            $out[(int) $w['id_feature']] = (string) $w['state'];
        }

        return $out;
    }

    /**
     * Zapisuje stan cechy w kategorii; pusty stan znaczy „bez decyzji".
     *
     * Przyjmujemy stan wprost, a nie przełączamy: decyzje przychodzą wszystkie
     * naraz z formularza, a przełączanie po stronie serwera odwracałoby te,
     * których sklep w ogóle nie ruszył.
     */
    public function ustaw(int $idCategory, int $idShop, int $idFeature, string $stan): void
    {
        $db = Db::getInstance();
        $gdzie = sprintf(
            'id_category = %d AND id_shop = %d AND id_feature = %d',
            $idCategory,
            $idShop,
            $idFeature
        );

        $db->execute(sprintf('DELETE FROM `%s` WHERE %s', _DB_PREFIX_ . self::TABELA, $gdzie));

        if (!in_array($stan, [self::PRZYPIETA, self::POMINIETA], true)) {
            return;
        }

        $db->insert(self::TABELA, [
            'id_category' => $idCategory,
            'id_shop' => $idShop,
            'id_feature' => $idFeature,
            'state' => pSQL($stan),
        ]);
    }

    public static function installTable(): bool
    {
        $sql = 'CREATE TABLE IF NOT EXISTS `' . _DB_PREFIX_ . self::TABELA . '` (
            `id_category` INT(11) UNSIGNED NOT NULL,
            `id_shop` INT(11) UNSIGNED NOT NULL,
            `id_feature` INT(11) UNSIGNED NOT NULL,
            `state` ENUM("pin","skip") NOT NULL,
            PRIMARY KEY (`id_category`, `id_shop`, `id_feature`)
        ) ENGINE=' . _MYSQL_ENGINE_ . ' DEFAULT CHARSET=utf8mb4;';

        return (bool) Db::getInstance()->execute($sql);
    }

    public static function uninstallTable(): bool
    {
        return (bool) Db::getInstance()->execute(
            'DROP TABLE IF EXISTS `' . _DB_PREFIX_ . self::TABELA . '`'
        );
    }
}
