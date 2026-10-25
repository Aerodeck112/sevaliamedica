<?php

namespace Tests\Unit;

use App\Core\Security\BlindIndex;
use Tests\TestCase;

class BlindIndexTest extends TestCase
{
    public function test_index_is_stable_normalized_and_purpose_bound(): void
    {
        $a = BlindIndex::make(' 1900101 123456 ', 'cnp');
        $this->assertSame($a, BlindIndex::make('1900101123456', 'cnp'));
        $this->assertNotSame($a, BlindIndex::make('1900101123456', 'telefon'));
        $this->assertStringNotContainsString('1900101123456', $a);
        $this->assertNull(BlindIndex::make('', 'cnp'));
    }

    public function test_mask_hides_middle_characters(): void
    {
        $this->assertSame('1•••••••••456', BlindIndex::mask('1900101123456'));
        $this->assertSame('••', BlindIndex::mask('12'));
    }
}
