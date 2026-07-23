<?php

namespace Tests\Feature;

use Tests\TestCase;

class CleanSetupQa2cUiTest extends TestCase
{
    public function test_bobot_nilai_confirmation_uses_green_confirm_and_neutral_cancel(): void
    {
        foreach ([
            resource_path('js/features/bobot-nilai-form.js'),
            resource_path('js/features/settings-modal.js'),
        ] as $path) {
            $source = file_get_contents($path);

            $this->assertStringContainsString("confirmButtonColor: '#16a34a'", $source);
            $this->assertStringContainsString("cancelButtonColor: '#6b7280'", $source);
            $this->assertStringNotContainsString("confirmButtonColor: '#3085d6'", $this->bobotConfirmationBlock($source));
            $this->assertStringNotContainsString("cancelButtonColor: '#d33'", $this->bobotConfirmationBlock($source));
        }
    }

    public function test_subject_teacher_filter_hides_ineligible_options_from_native_selects(): void
    {
        $source = file_get_contents(resource_path('js/features/subject-form.js'));

        $this->assertStringContainsString("option.style.display = available ? '' : 'none';", $source);
        $this->assertStringContainsString('isAssignedToTeachClass(option, selectedKelasId)', $source);
    }

    private function bobotConfirmationBlock(string $source): string
    {
        $start = strpos($source, "title: 'Konfirmasi Perubahan Bobot Nilai'");

        if ($start === false) {
            return $source;
        }

        return substr($source, $start, 500);
    }
}
