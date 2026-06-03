<?php

namespace Tests\Feature;

use App\Models\Facility;
use Database\Seeders\PagsanjanFacilitySeeder;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class FacilitySeederTest extends TestCase
{
    use DatabaseTransactions;

    #[Test]
    public function all_16_pagsanjan_barangays_have_at_least_3_facilities(): void
    {
        $this->seed(PagsanjanFacilitySeeder::class);

        $barangays = [
            'Anibong', 'Biñan', 'Buboy', 'Cabanbanan', 'Calusiche', 'Dingin',
            'Lambac', 'Layugan', 'Magdapio', 'Maulawin', 'Pinagsanjan',
            'Barangay I (Poblacion)', 'Barangay II (Poblacion)',
            'Sabang', 'Sampaloc', 'San Isidro',
        ];

        foreach ($barangays as $barangay) {
            $count = Facility::where('barangay', $barangay)->count();
            $this->assertGreaterThanOrEqual(
                3,
                $count,
                "Barangay '{$barangay}' must have at least 3 facilities, found {$count}"
            );
        }
    }
}
