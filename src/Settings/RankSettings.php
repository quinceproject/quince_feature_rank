<?php
/**
 * Quince
 *
 * @author    Quince Project <quinceproject@proton.me>
 * @copyright Since 2026 Marcin Gajewski
 * @license   https://opensource.org/licenses/OSL-3.0 Open Software License (OSL 3.0)
 */

declare(strict_types=1);

namespace Quince\FeatureRank\Settings;

if (!defined('_PS_VERSION_')) {
    exit;
}

use Configuration;
use Quince\Engine\Settings\ConfigStore;

/**
 * Warunki, po których cecha wchodzi na listę.
 *
 * Ustawia je sklep, nie kod, bo zależą od tego, jak kompletnie katalog ma
 * uzupełnione cechy.
 *
 * Po każdej zmianie ranking wymaga przeliczenia, bo zapisane wyniki powstały
 * przy poprzednich warunkach. Robi to ekran, zaraz po zapisie.
 */
final class RankSettings
{
    private const PREFIX = 'QUINCE_FRANK_';

    /** @var array<string,string> klucz => wartość domyślna */
    private const SCHEMA = [
        // Ile procent produktów w kategorii musi mieć cechę wypełnioną.
        'MIN_COVERAGE' => '60',

        // Czy pomijać cechę, która u wszystkich produktów ma tę samą wartość.
        // Domyślnie tak: prawda, która nie odróżnia niczego od niczego, zajmuje
        // miejsce w liście liczącej kilka wierszy.
        'SKIP_UNIFORM' => '1',

        // Czy zmiana w katalogu ma sama unieważniać ranking kategorii.
        // Domyślnie nie: sklep, który dopiero wgrywa towar, dostawałby przy każdym
        // imporcie tyle unieważnień, ile produktów.
        'AUTO' => '0',

        // Filtry na listingu: czy przeliczenie wszystkich kategorii ustawia też
        // cechy w szablonie modułu filtrów, i w którym szablonie. Bez wskazanego
        // szablonu moduł niczego w filtrach nie zapisuje.
        'FACETS' => '0',
        'FACETS_TEMPLATE' => '0',
    ];

    /** Kiedy moduł ostatnio zapisał cechy w szablonie filtrów. */
    private const FILTRY_KIEDY = 'QUINCE_FRANK_FACETS_UPDATED';

    /** Kategorie czekające na przeliczenie: lista identyfikatorów po przecinku. */
    private const BRUDNE = 'QUINCE_FRANK_DIRTY';

    /** @return array<string,string> */
    public function all(): array
    {
        $out = [];

        foreach (self::SCHEMA as $key => $default) {
            $value = Configuration::get(self::PREFIX . $key);
            $out[$key] = ($value === false || $value === null) ? $default : (string) $value;
        }

        return $out;
    }

    /**
     * Same warunki rankingu, bez ustawień filtrów: ich zmiana wymaga
     * przeliczenia całego sklepu, a przełączenie filtrów nie.
     *
     * @return array<string,string>
     */
    public function warunki(): array
    {
        return array_diff_key($this->all(), ['FACETS' => true, 'FACETS_TEMPLATE' => true]);
    }

    /** Próg wypełnienia jako ułamek, w takiej postaci liczy go kalkulator. */
    public function minPokrycie(): float
    {
        return $this->procent() / 100;
    }

    /** Próg wypełnienia w procentach, przycięty do sensownego zakresu. */
    public function procent(): int
    {
        return max(10, min(100, (int) $this->all()['MIN_COVERAGE']));
    }

    public function pomijajJednakowe(): bool
    {
        return $this->all()['SKIP_UNIFORM'] === '1';
    }

    public function samoczynnie(): bool
    {
        return $this->all()['AUTO'] === '1';
    }

    public function filtryWlaczone(): bool
    {
        return $this->all()['FACETS'] === '1';
    }

    public function szablonFiltrow(): int
    {
        return (int) $this->all()['FACETS_TEMPLATE'];
    }

    public function filtryKiedy(): ?string
    {
        $wartosc = (string) Configuration::get(self::FILTRY_KIEDY);

        return $wartosc === '' ? null : $wartosc;
    }

    public function zapamietajFiltry(): void
    {
        ConfigStore::set(self::FILTRY_KIEDY, date('Y-m-d H:i:s'));
    }

    /**
     * Zaznacza kategorie jako wymagające przeliczenia.
     *
     * Zaznaczamy, a nie przeliczamy od razu: import pięciu tysięcy produktów
     * wywołałby przeliczenie pięć tysięcy razy, a wynik i tak byłby potrzebny
     * dopiero przy pierwszym wejściu klienta. Przeliczenie robi `Provider`,
     * gdy ktoś tej kategorii faktycznie potrzebuje.
     *
     * @param int[] $kategorie
     */
    public function zaznaczDoPrzeliczenia(array $kategorie): void
    {
        if ($kategorie === []) {
            return;
        }

        $razem = array_unique(array_merge($this->doPrzeliczenia(), array_map('intval', $kategorie)));
        sort($razem);

        ConfigStore::set(self::BRUDNE, implode(',', $razem));
    }

    /** @return int[] */
    public function doPrzeliczenia(): array
    {
        $zapisane = (string) Configuration::get(self::BRUDNE);

        if ($zapisane === '') {
            return [];
        }

        return array_values(array_filter(array_map('intval', explode(',', $zapisane))));
    }

    public function odznacz(int $idCategory): void
    {
        $zostaje = array_values(array_diff($this->doPrzeliczenia(), [$idCategory]));

        ConfigStore::set(self::BRUDNE, implode(',', $zostaje));
    }

    /**
     * @param array<string,mixed> $values
     */
    public function save(array $values): void
    {
        if (array_key_exists('MIN_COVERAGE', $values)) {
            $procent = max(10, min(100, (int) $values['MIN_COVERAGE']));
            ConfigStore::set(self::PREFIX . 'MIN_COVERAGE', (string) $procent);
        }

        // Makro przełącznika wysyła przed nim pole ukryte z zerem, więc wartość
        // przychodzi zawsze, także przy wyłączonym. Osobny znacznik formularza
        // nie jest tu potrzebny.
        if (array_key_exists('SKIP_UNIFORM', $values)) {
            ConfigStore::set(self::PREFIX . 'SKIP_UNIFORM', $values['SKIP_UNIFORM'] === '1' ? '1' : '0');
        }

        if (array_key_exists('AUTO', $values)) {
            ConfigStore::set(self::PREFIX . 'AUTO', $values['AUTO'] === '1' ? '1' : '0');
        }

        if (array_key_exists('FACETS', $values)) {
            ConfigStore::set(self::PREFIX . 'FACETS', $values['FACETS'] === '1' ? '1' : '0');
        }

        if (array_key_exists('FACETS_TEMPLATE', $values)) {
            ConfigStore::set(self::PREFIX . 'FACETS_TEMPLATE', (string) max(0, (int) $values['FACETS_TEMPLATE']));
        }
    }
}
