<?php

namespace Tests\Feature\Owner;

use App\Models\QrCode;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Support\World;
use Tests\TestCase;

/**
 * Owner notes train, Amendment 31 (copy-inventory 17.21):
 * [ON-QR-1] the Screen poster is retired, [MIX-04] every print
 * option states where it goes and its real print size,
 * [ON-SPOT-1] the by-spot intro says counting is per code and
 * the name is only a label, [ON-SPOT-2] default code names are
 * the format names, [ON-SPOT-3] each code can be renamed on
 * the panel (QrCode.label), the name survives replacement, and
 * the Replace modal + flash read the current display name.
 */
class QrSpotRenameTest extends TestCase
{
    use RefreshDatabase;

    private function qrHtml($business): string
    {
        $owner = World::owner($business);
        QrCode::ensurePlacements($business);

        return $this->actingAs($owner)->get(route('owner.qr'))->assertOk()->getContent();
    }

    /** The by-spot row HTML for one placement. */
    private function row(string $html, string $placement): string
    {
        $this->assertMatchesRegularExpression(
            '/data-placement="'.preg_quote($placement, '/').'"/',
            $html,
            "The by-spot panel has no row for {$placement}."
        );
        preg_match('/data-placement="'.preg_quote($placement, '/').'".*?<\/li>/s', $html, $m);

        return $m[0] ?? '';
    }

    public function test_on_qr_1_screen_poster_is_retired_and_its_variant_404s(): void
    {
        $business = World::business();
        $owner = World::owner($business);
        $html = $this->qrHtml($business);

        // Absence pins [ON-QR-1]: the row and its gloss render
        // nowhere on the QR page; the Mini-poster row stays.
        $this->assertStringNotContainsString('Screen poster', $html);
        $this->assertStringNotContainsString('For a tablet or TV screen', $html);
        $this->assertStringContainsString('Mini-poster', $html);

        // The variant stops resolving, like any unknown variant.
        $this->actingAs($owner)->get(route('owner.qr.poster', ['variant' => 'screen']))->assertNotFound();
        $this->actingAs($owner)->get(route('owner.qr.poster', ['variant' => 'screen', 'print' => 1]))->assertNotFound();
        $this->actingAs($owner)->get(route('owner.qr.poster', ['variant' => 'nonsense']))->assertNotFound();
        // The surviving variants still resolve.
        foreach (['counter', 'window', 'mini', 'tent', 'receipt'] as $variant) {
            $this->actingAs($owner)->get(route('owner.qr.poster', ['variant' => $variant]))->assertOk();
        }
    }

    public function test_mix_04_dimension_strings_are_verbatim_and_old_lines_are_gone(): void
    {
        $html = $this->qrHtml(World::business());

        // Start here (counter poster).
        $this->assertStringContainsString(
            'It stands by your register, where customers see it while they pay. It prints on half a sheet of paper (5.8 x 8.3 in). Once it is up, it keeps working on its own.',
            $html
        );

        $lines = [
            'counter' => 'By the register, where people pay. Prints on half a sheet of paper (5.8 x 8.3 in).',
            'window' => 'In the front window, facing out. A full sheet of paper (8.3 x 11.7 in), so people can read it from the sidewalk.',
            'table' => 'Standing up on your tables. The sheet is 8.3 x 5.8 in and folds in half to stand.',
            'receipt' => 'Handed out with the receipt. A small card, 3.3 x 2.2 in, the size of a business card.',
        ];
        foreach ($lines as $placement => $line) {
            $this->assertStringContainsString($line, $this->row($html, $placement), "The {$placement} row lost its [MIX-04] line.");
        }

        // The Mini-poster line in Also available.
        $this->assertStringContainsString('A smaller counter poster, for tight spaces. Prints at 4.1 x 5.8 in.', $html);

        // The superseded [MIX-03] lines render nowhere.
        foreach ([
            'It stands by your register, where customers see it while they pay. It prints on half a sheet of paper, and it is the one most shops put up first.',
            'By the register, where people pay. Prints on half a sheet of paper.',
            'In the front window, facing out. A full page, so people can read it from the sidewalk.',
            'Standing up on your tables. A small folded card.',
            'Handed out with the receipt. A small card.',
            // The old mini gloss ended the row's gloss span;
            // the new line continues past it with the size.
            'A smaller counter poster, for tight spaces.</span>',
        ] as $old) {
            $this->assertStringNotContainsString($old, $html, "Superseded line still renders: {$old}");
        }
    }

    public function test_on_spot_1_intro_is_verbatim(): void
    {
        $html = $this->qrHtml(World::business());

        $this->assertStringContainsString(
            'Every spot gets its own code, so you can see which spot brings people in. Scans are counted per code: the numbers under a code come from that printed code only. The name is only a label. Rename any code to match where you really put it.',
            $html
        );
    }

    public function test_on_spot_2_default_display_names_are_the_format_names(): void
    {
        $business = World::business();
        $codes = QrCode::ensurePlacements($business);

        $this->assertSame('Counter poster', $codes['counter']->displayName());
        $this->assertSame('Window poster', $codes['window']->displayName());
        $this->assertSame('Table tent', $codes['table']->displayName());
        $this->assertSame('Receipt card', $codes['receipt']->displayName());

        $html = $this->qrHtml($business);
        foreach (['counter' => 'Counter poster', 'window' => 'Window poster', 'table' => 'Table tent', 'receipt' => 'Receipt card'] as $placement => $name) {
            $row = $this->row($html, $placement);
            $this->assertStringContainsString(
                '<span class="type-body block font-semibold text-text-1">'.$name.'</span>',
                $row,
                "The {$placement} row must display its format name."
            );
        }

        // The bare location words no longer render as code names.
        foreach (['Counter', 'Window', 'Table', 'Receipt'] as $bare) {
            $this->assertStringNotContainsString(
                '<span class="type-body block font-semibold text-text-1">'.$bare.'</span>',
                $html,
                "The bare location word {$bare} still renders as a code name."
            );
        }
    }

    public function test_on_spot_3_rename_form_renders_per_row_with_its_default_in_the_helper(): void
    {
        $html = $this->qrHtml(World::business());

        $this->assertSame(4, substr_count($html, 'Name for this code'));
        $this->assertSame(4, substr_count($html, '>Rename</button>'));
        $this->assertSame(4, substr_count($html, '>Save name</button>'));
        foreach (['counter' => 'Counter poster', 'window' => 'Window poster', 'table' => 'Table tent', 'receipt' => 'Receipt card'] as $placement => $default) {
            $row = $this->row($html, $placement);
            $this->assertStringContainsString(route('owner.qr.placement.rename', ['placement' => $placement]), $row);
            $this->assertStringContainsString(
                'The name shows on this page only. Your posters do not change. Leave it empty and save to use the standard name, '.$default.'.',
                $row,
                "The {$placement} rename helper must name its own standard name."
            );
            $this->assertStringContainsString('id="name-'.$placement.'"', $row);
        }
    }

    public function test_on_spot_3_rename_sets_the_label_flashes_and_shows_everywhere_the_name_reads(): void
    {
        $business = World::business();
        $owner = World::owner($business);
        QrCode::ensurePlacements($business);

        $this->actingAs($owner)
            ->post(route('owner.qr.placement.rename', ['placement' => 'counter']), ['name' => 'Front door'])
            ->assertRedirect(route('owner.qr'))
            ->assertSessionHas('status', 'Code renamed.');

        $code = QrCode::activeForPlacement($business, 'counter');
        $this->assertSame('Front door', $code->label);
        $this->assertSame('Front door', $code->displayName());

        $html = $this->qrHtml($business);
        $row = $this->row($html, 'counter');
        $this->assertStringContainsString('<span class="type-body block font-semibold text-text-1">Front door</span>', $row);
        // The Replace modal reads the current display name.
        $this->assertStringContainsString('Printed copies of the Front door code will stop working right away. Your other codes keep working.', $row);
    }

    public function test_on_spot_3_clearing_the_name_restores_the_format_default(): void
    {
        $business = World::business();
        $owner = World::owner($business);
        $codes = QrCode::ensurePlacements($business);
        $codes['window']->forceFill(['label' => 'Shop window'])->save();

        $this->actingAs($owner)
            ->post(route('owner.qr.placement.rename', ['placement' => 'window']), ['name' => ''])
            ->assertRedirect(route('owner.qr'))
            ->assertSessionHas('status', 'Code renamed.');

        $code = QrCode::activeForPlacement($business, 'window');
        $this->assertNull($code->label);
        $this->assertSame('Window poster', $code->displayName());

        $html = $this->qrHtml($business);
        $this->assertStringContainsString('<span class="type-body block font-semibold text-text-1">Window poster</span>', $this->row($html, 'window'));
    }

    public function test_on_spot_3_over_40_characters_is_refused_and_the_error_renders_at_the_field(): void
    {
        $business = World::business();
        $owner = World::owner($business);
        QrCode::ensurePlacements($business);

        $tooLong = str_repeat('a', 41);
        $this->actingAs($owner)
            ->from(route('owner.qr'))
            ->post(route('owner.qr.placement.rename', ['placement' => 'counter']), ['name' => $tooLong])
            ->assertRedirect(route('owner.qr'))
            ->assertSessionHasErrorsIn('rename_counter', 'name');

        // Refusal: the stored label did not change.
        $this->assertNull(QrCode::activeForPlacement($business, 'counter')->label);

        // Render (LR-28-1: refusal AND render are both pinned):
        // the verbatim error sits at the counter row's field,
        // with the aria wiring, and in no other row.
        $html = $this->qrHtml($business);
        $row = $this->row($html, 'counter');
        $this->assertStringContainsString('Keep the name under 40 characters.', $row);
        $this->assertStringContainsString('id="name-counter-error"', $row);
        $this->assertMatchesRegularExpression(
            '/id="name-counter"[^>]*aria-invalid="true"[^>]*aria-describedby="[^"]*name-counter-error/',
            $row,
            'The counter name field must carry aria-invalid and an aria-describedby naming its error.'
        );
        foreach (['window', 'table', 'receipt'] as $placement) {
            $this->assertStringNotContainsString('Keep the name under 40 characters.', $this->row($html, $placement));
        }

        // Exactly 40 characters is valid.
        $this->actingAs($owner)
            ->post(route('owner.qr.placement.rename', ['placement' => 'counter']), ['name' => str_repeat('b', 40)])
            ->assertSessionHasNoErrors();
        $this->assertSame(str_repeat('b', 40), QrCode::activeForPlacement($business, 'counter')->label);
    }

    public function test_on_spot_3_the_name_survives_replacement_and_replace_all(): void
    {
        $business = World::business();
        $owner = World::owner($business);
        QrCode::ensurePlacements($business);

        $this->actingAs($owner)
            ->post(route('owner.qr.placement.rename', ['placement' => 'table']), ['name' => 'Patio tables'])
            ->assertSessionHas('status', 'Code renamed.');

        // The replace flash reads the current display name.
        $this->actingAs($owner)
            ->post(route('owner.qr.placement.regenerate', ['placement' => 'table']))
            ->assertRedirect(route('owner.qr'))
            ->assertSessionHas('status', 'New code is ready for Patio tables. Print a new poster for that spot.');

        $fresh = QrCode::activeForPlacement($business, 'table');
        $this->assertSame('Patio tables', $fresh->label, 'The replacement code must inherit the name.');
        $this->assertSame('Patio tables', $fresh->displayName());

        // Replace-all carries every placement's name too.
        $this->actingAs($owner)
            ->post(route('owner.qr.placement.rename', ['placement' => 'receipt']), ['name' => 'Till cards']);
        QrCode::replaceAllPlacements($business);
        $this->assertSame('Patio tables', QrCode::activeForPlacement($business, 'table')->label);
        $this->assertSame('Till cards', QrCode::activeForPlacement($business, 'receipt')->label);
    }

    public function test_on_spot_3_ensure_placements_self_heal_reissue_preserves_the_name(): void
    {
        $business = World::business();
        $codes = QrCode::ensurePlacements($business);

        // A code whose sealed copy is missing is revoked and
        // reissued by ensurePlacements; the name must survive.
        $codes['window']->forceFill(['label' => 'Shop window', 'token_encrypted' => null])->save();

        $healed = QrCode::ensurePlacements($business);
        $this->assertSame('revoked', $codes['window']->fresh()->state);
        $this->assertNotSame($codes['window']->id, $healed['window']->id);
        $this->assertSame('Shop window', $healed['window']->label);
    }

    public function test_on_spot_3_rename_is_scoped_to_the_owners_business_and_placement(): void
    {
        $business = World::business();
        $other = World::business();
        $owner = World::owner($business);
        QrCode::ensurePlacements($business);
        QrCode::ensurePlacements($other);

        $this->actingAs($owner)
            ->post(route('owner.qr.placement.rename', ['placement' => 'counter']), ['name' => 'Mine only'])
            ->assertSessionHas('status', 'Code renamed.');

        $this->assertSame('Mine only', QrCode::activeForPlacement($business, 'counter')->label);
        $this->assertNull(QrCode::activeForPlacement($other, 'counter')->label);
        $this->assertNull(QrCode::activeForPlacement($business, 'window')->label);

        // An unknown placement 404s, like the replace route.
        $this->actingAs($owner)
            ->post(route('owner.qr.placement.rename', ['placement' => 'door']), ['name' => 'Nope'])
            ->assertNotFound();
    }
}
