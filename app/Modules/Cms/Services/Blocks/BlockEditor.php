<?php

namespace App\Modules\Cms\Services\Blocks;

use App\Modules\Cms\Models\SectionTemplate;
use App\Modules\Cms\Services\Html\InlinePurifier;
use App\Modules\Cms\Services\Html\StructureSanitizer;
use Illuminate\Support\Str;
use InvalidArgumentException;

/**
 * Aplică modificările din editor asupra listei de blocuri. Primește doar valori de sloturi și operații;
 * structura HTML a blocurilor provine întotdeauna din versiunea salvată sau din șabloane, niciodată din cerere.
 */
final class BlockEditor
{
    /**
     * @param  array  $blocks  blocurile versiunii curente
     * @param  array<string, array<string, mixed>>  $slotValues  uid bloc => (id slot => valoare)
     * @param  array<string, string>  $richValues  uid bloc => HTML (doar pentru blocurile „rich”)
     * @return array{0: array, 1: string} blocurile noi și descrierea modificării
     */
    public static function apply(array $blocks, array $slotValues, array $richValues, ?string $op): array
    {
        $byUid = [];
        foreach ($blocks as $i => $block) {
            $byUid[$block['uid']] = $i;
        }

        foreach ($slotValues as $uid => $values) {
            if (! isset($byUid[$uid]) || ! is_array($values)) {
                continue;
            }
            $block = &$blocks[$byUid[$uid]];
            if (isset($block['html']) && in_array($block['type'], ['html', 'team', 'blog_latest', 'form'], true)) {
                $block['html'] = Slots::apply($block['html'], $values);
            }
            unset($block);
        }
        foreach ($richValues as $uid => $html) {
            if (isset($byUid[$uid]) && $blocks[$byUid[$uid]]['type'] === 'rich') {
                $blocks[$byUid[$uid]]['html'] = InlinePurifier::rich((string) $html);
            }
        }

        $note = 'Modificare text';
        if ($op) {
            [$blocks, $note] = self::operation($blocks, $byUid, $op);
        }

        return [array_values($blocks), $note];
    }

    private static function operation(array $blocks, array $byUid, string $op): array
    {
        $parts = explode(':', $op);
        $action = $parts[0];
        $uid = $parts[1] ?? '';
        $index = $byUid[$uid] ?? null;
        $requireBlock = fn () => $index === null ? throw new InvalidArgumentException('Blocul nu există.') : $index;

        switch ($action) {
            case 'up':
            case 'down':
                $i = $requireBlock();
                $j = $action === 'up' ? $i - 1 : $i + 1;
                if ($j >= 0 && $j < count($blocks)) {
                    [$blocks[$i], $blocks[$j]] = [$blocks[$j], $blocks[$i]];
                }

                return [$blocks, $action === 'up' ? 'Secțiune mutată mai sus' : 'Secțiune mutată mai jos'];
            case 'hide':
            case 'show':
                $blocks[$requireBlock()]['hidden'] = $action === 'hide';

                return [$blocks, $action === 'hide' ? 'Secțiune ascunsă' : 'Secțiune afișată'];
            case 'delete':
                array_splice($blocks, $requireBlock(), 1);

                return [$blocks, 'Secțiune ștearsă'];
            case 'duplicate':
                $i = $requireBlock();
                $copy = $blocks[$i];
                $copy['uid'] = self::newUid();
                array_splice($blocks, $i + 1, 0, [$copy]);

                return [$blocks, 'Secțiune duplicată'];
            case 'dup-item':
                $i = $requireBlock();
                $blocks[$i]['html'] = Slots::duplicateRepeat($blocks[$i]['html'], $parts[2] ?? '');

                return [$blocks, 'Element duplicat'];
            case 'rm-item':
                $i = $requireBlock();
                $blocks[$i]['html'] = Slots::removeRepeat($blocks[$i]['html'], $parts[2] ?? '');

                return [$blocks, 'Element eliminat'];
            case 'add':
                // add:<uid după care se inserează sau „start”>:<id șablon | rich>
                $source = $parts[2] ?? '';
                if ($source === 'rich') {
                    $new = ['uid' => self::newUid(), 'type' => 'rich', 'label' => 'Text', 'snippet' => null, 'hidden' => false, 'html' => '<p>Text nou</p>'];
                } else {
                    $template = SectionTemplate::findOrFail((int) $source);
                    $new = $template->block;
                    $new['uid'] = self::newUid();
                    $new['hidden'] = false;
                    $new['html'] = Slots::ensureRepeatIds(StructureSanitizer::cleanHtml(self::stripRepeatIds($new['html'] ?? '')));
                }
                $position = $uid === 'start' ? 0 : ($index === null ? count($blocks) : $index + 1);
                array_splice($blocks, $position, 0, [$new]);

                return [$blocks, 'Secțiune adăugată'];
        }

        throw new InvalidArgumentException('Operație necunoscută.');
    }

    private static function stripRepeatIds(string $html): string
    {
        return (string) preg_replace('/\sdata-cms-repeat-id="[^"]*"/', '', $html);
    }

    public static function newUid(): string
    {
        return 'b'.Str::lower(Str::random(10));
    }
}
