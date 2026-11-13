<?php
/**
 * Quince
 *
 * @author    Quince Project <quinceproject@proton.me>
 * @copyright Since 2026 Marcin Gajewski
 * @license   https://opensource.org/licenses/OSL-3.0 Open Software License (OSL 3.0)
 */

declare(strict_types=1);

namespace Quince\FeatureRank\Controller\Admin;

use Db;
use Module;
use Quince\Engine\Controller\Admin\QuinceController;
use Quince\FeatureRank\Rank\Calculator;
use Quince\FeatureRank\Rank\Overrides;
use Quince\FeatureRank\Rank\Podglad;
use Quince\FeatureRank\Rank\Storage;
use Quince\FeatureRank\Settings\RankSettings;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Ekran rankingu cech.
 *
 * Ranking jest wyliczany, nie ustawiany, więc ten ekran przede wszystkim POKAZUJE,
 * dlaczego w danej kategorii wygrały akurat te cechy.
 *
 * Widok jest PO JEDNEJ KATEGORII, a nie zrzutem wszystkich naraz.
 */
class SettingsController extends QuinceController
{
    private const DOMENA = 'Modules.Quincefeaturerank.Admin';

    public function __construct(
        private readonly Storage $storage,
        private readonly RankSettings $ustawienia = new RankSettings()
    ) {
    }

    public function indexAction(Request $request): Response
    {
        $idShop = (int) $this->getContext()->shop->id;
        $idLang = (int) $this->getContext()->language->id;

        $kategorie = $this->kategorie($idLang, $idShop);
        $wybrana = $this->wybrana($request, $kategorie);

        return $this->renderPanel(
            '@Modules/quince_feature_rank/views/templates/admin/settings.html.twig',
            [
                'kategorie' => $kategorie,
                'wybrana' => $wybrana,
                'podglad' => $wybrana === null
                    ? ['produktow' => 0, 'cechy' => []]
                    : (new Podglad())->dlaKategorii($wybrana['id'], $idShop, $idLang),
                'podsumowanie' => $this->podsumowanie($kategorie, $idShop),
                'progi' => $this->ustawienia->all(),
                'pokrycie' => $this->ustawienia->procent(),
                'layoutTitle' => 'Feature ranking',
                'layoutTitleDomain' => self::DOMENA,
                'quinceRoute' => 'quince_feature_rank_settings',
            ]
        );
    }

    /**
     * Zapisuje i przelicza dokładnie tyle, ile zmiana obejmuje.
     *
     * Decyzja przy cesze dotyczy jednej kategorii, więc przelicza się jedna
     * kategoria. Warunki z górnej karty obowiązują w całym sklepie, więc ich
     * zmiana pociąga przeliczenie wszystkiego. Bez tego rozróżnienia zapis
     * jednego przypięcia uruchamiał przeliczenie kilku tysięcy kategorii.
     *
     * Przeliczenie idzie od razu, a nie na żądanie: bez niego zapisane wyniki
     * zostałyby te sprzed zmiany, więc ekran pokazywałby jedno, a karty
     * produktów drugie, i nic by nie mówiło, że trzeba jeszcze coś kliknąć.
     */
    public function saveAction(Request $request): RedirectResponse
    {
        if (!$this->isCsrfTokenValid('quince_feature_rank_save', $request->request->get('_csrf_token'))) {
            $this->addFlash('error', $this->trans('Invalid security token, please try again.', self::DOMENA));

            return $this->redirectToRoute('quince_feature_rank_settings', [
                'id_category' => (int) $request->request->get('id_category'),
            ]);
        }

        $idShop = (int) $this->getContext()->shop->id;
        $idCategory = (int) $request->request->get('id_category');

        $przedZmiana = $this->ustawienia->all();
        $this->ustawienia->save($request->request->all());
        $warunkiZmienione = $this->ustawienia->all() !== $przedZmiana;

        $this->zapiszDecyzje($request, $idShop);

        $modul = Module::getInstanceByName('quince_feature_rank');

        if ($warunkiZmienione) {
            $ile = $modul->przeliczSklep($idShop);

            $this->addFlash('success', $this->trans(
                'Saved. Conditions changed, so the ranking was recomputed for %count% categories.',
                self::DOMENA,
                ['%count%' => $ile]
            ));
        } else {
            if ($idCategory > 0) {
                $modul->przeliczKategorie($idCategory, $idShop);
            }

            $this->addFlash('success', $this->trans(
                'Saved. Ranking recomputed for this category.',
                self::DOMENA
            ));
        }

        // Powrót na oglądaną kategorię, a nie na domyślną: decyzje w tabeli są
        // jej decyzjami, więc ekran po zapisie musi pokazać ich skutek.
        return $this->redirectToRoute('quince_feature_rank_settings', [
            'id_category' => $idCategory,
        ]);
    }

    /**
     * Decyzje o cechach w oglądanej kategorii: przypięcie, pominięcie albo brak.
     *
     * Przychodzą wszystkie naraz, razem z warunkami: jedno kliknięcie zapisu
     * po dowolnej liczbie zmian w tabeli.
     */
    private function zapiszDecyzje(Request $request, int $idShop): void
    {
        $idCategory = (int) $request->request->get('id_category');
        $przyslane = $request->request->all('override');

        if ($idCategory <= 0 || !is_array($przyslane)) {
            return;
        }

        $overrides = new Overrides();

        foreach ($przyslane as $idFeature => $stan) {
            $overrides->ustaw($idCategory, $idShop, (int) $idFeature, (string) $stan);
        }
    }

    /**
     * Przelicza ranking dla bieżącego sklepu.
     *
     * Ręcznie, z panelu, żeby skutek zmian w cechach było widać od razu,
     * bez czekania na przeliczenie w tle.
     */
    public function recomputeAction(Request $request): RedirectResponse
    {
        if (!$this->isCsrfTokenValid('quince_feature_rank_recompute', $request->request->get('_csrf_token'))) {
            $this->addFlash('error', $this->trans('Invalid security token, please try again.', self::DOMENA));

            return $this->redirectToRoute('quince_feature_rank_settings');
        }

        $modul = Module::getInstanceByName('quince_feature_rank');
        $ile = $modul->przeliczSklep((int) $this->getContext()->shop->id);

        $this->addFlash('success', $this->trans(
            'Ranking recomputed for %count% categories.',
            self::DOMENA,
            ['%count%' => $ile]
        ));

        return $this->redirectToRoute('quince_feature_rank_settings');
    }

    /**
     * Kategoria pokazywana na ekranie: z adresu albo pierwsza, która coś ma.
     *
     * Pierwsza Z RANKINGIEM, a nie pierwsza z brzegu: ekran otwarty na kategorii
     * bez wyniku wygląda jak zepsuty, choć pusty wynik jest tam poprawny.
     *
     * @param array<int,array<string,mixed>> $kategorie
     *
     * @return array<string,mixed>|null
     */
    private function wybrana(Request $request, array $kategorie): ?array
    {
        $zAdresu = (int) $request->query->get('id_category');

        foreach ($kategorie as $kategoria) {
            if ($kategoria['id'] === $zAdresu) {
                return $kategoria;
            }
        }

        foreach ($kategorie as $kategoria) {
            if ($kategoria['ma_ranking']) {
                return $kategoria;
            }
        }

        return $kategorie[0] ?? null;
    }

    /**
     * Drzewo kategorii z informacją, czy każda ma własny ranking.
     *
     * @return array<int,array{id:int,name:string,depth:int,parent:int,sciezka:int[],dzieci:bool,ma_ranking:bool}>
     */
    private function kategorie(int $idLang, int $idShop): array
    {
        // Złączenie po języku ORAZ po sklepie: `category_lang` ma wiersz na każdą
        // parę język–sklep, więc samo `id_lang` daje tyle kopii każdej kategorii,
        // ile sklep ma sklepów.
        $wiersze = Db::getInstance()->executeS(
            'SELECT c.id_category, c.id_parent, c.level_depth, cl.name,
                    (SELECT COUNT(*) FROM ' . _DB_PREFIX_ . Storage::TABELA . ' r
                      WHERE r.id_category = c.id_category AND r.id_shop = ' . $idShop . ') AS cech
             FROM ' . _DB_PREFIX_ . 'category c
             INNER JOIN ' . _DB_PREFIX_ . 'category_lang cl
                 ON cl.id_category = c.id_category
                 AND cl.id_lang = ' . $idLang . '
                 AND cl.id_shop = ' . $idShop . '
             INNER JOIN ' . _DB_PREFIX_ . 'category_shop cs
                 ON cs.id_category = c.id_category AND cs.id_shop = ' . $idShop . '
             WHERE c.active = 1 AND c.level_depth > 1
             ORDER BY c.nleft'
        );

        $kategorie = [];
        $rodzice = [];

        foreach (is_array($wiersze) ? $wiersze : [] as $w) {
            $id = (int) $w['id_category'];
            $parent = (int) $w['id_parent'];
            $rodzice[$id] = $parent;

            $kategorie[$id] = [
                'id' => $id,
                'name' => (string) $w['name'],
                'parent' => $parent,
                // Poziom liczony od kategorii głównej, żeby wcięcie zaczynało się od zera.
                'depth' => max(0, (int) $w['level_depth'] - 2),
                'ma_ranking' => (int) $w['cech'] > 0,
            ];
        }

        // Ścieżka przodków i informacja o dzieciach: drzewo w panelu musi wiedzieć,
        // co zwinąć i przy czym postawić strzałkę. Liczymy to raz tutaj, bo w szablonie
        // wychodziłaby z tego pętla po pętli przy każdym wierszu.
        foreach ($kategorie as $id => $kategoria) {
            $sciezka = [];
            $biezacy = $kategoria['parent'];

            while (isset($rodzice[$biezacy])) {
                $sciezka[] = $biezacy;
                $biezacy = $rodzice[$biezacy];
            }

            $kategorie[$id]['sciezka'] = $sciezka;
            $kategorie[$id]['dzieci'] = in_array($id, $rodzice, true);
        }

        return array_values($kategorie);
    }

    /**
     * Stan całości nad drzewem: ile kategorii ma własny ranking, ile bierze z góry.
     *
     * @param array<int,array<string,mixed>> $kategorie
     *
     * @return array<string,mixed>
     */
    private function podsumowanie(array $kategorie, int $idShop): array
    {
        $zRankingiem = count(array_filter($kategorie, static fn (array $k): bool => $k['ma_ranking']));

        return [
            'wszystkich' => count($kategorie),
            'z_rankingiem' => $zRankingiem,
            'dziedziczy' => count($kategorie) - $zRankingiem,
            'kiedy' => $this->kiedyLiczone($idShop),
        ];
    }

    private function kiedyLiczone(int $idShop): ?string
    {
        $wartosc = Db::getInstance()->getValue(
            'SELECT MAX(computed_at) FROM ' . _DB_PREFIX_ . Storage::TABELA . ' WHERE id_shop = ' . $idShop
        );

        return $wartosc === false || $wartosc === null || $wartosc === '' ? null : (string) $wartosc;
    }
}
