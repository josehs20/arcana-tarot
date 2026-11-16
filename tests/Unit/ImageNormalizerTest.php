<?php

namespace Tests\Unit;

use App\Support\Images\ImageNormalizer;
use App\Support\Images\ImageProfile;
use Illuminate\Http\UploadedFile;
use Illuminate\Validation\ValidationException;
use PHPUnit\Framework\Attributes\RequiresPhpExtension;
use Tests\TestCase;

class ImageNormalizerTest extends TestCase
{
    public function test_rejects_content_that_is_not_a_supported_raster_image(): void
    {
        $file = UploadedFile::fake()->createWithContent(
            'carta.svg',
            '<svg xmlns="http://www.w3.org/2000/svg"><script>alert(1)</script></svg>',
        );

        try {
            (new ImageNormalizer)->normalize($file, ImageProfile::TarotCard, 'image');
            $this->fail('O normalizador aceitou um SVG.');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey('image', $exception->errors());
            $this->assertStringContainsString('JPEG, PNG ou WebP', $exception->errors()['image'][0]);
        }
    }

    public function test_rejects_images_above_the_pixel_budget_before_decoding(): void
    {
        config(['images.max_pixels' => 1_000_000]);

        $file = UploadedFile::fake()->createWithContent('bomba.png', $this->pngHeader(5000, 5000));

        try {
            (new ImageNormalizer)->normalize($file, ImageProfile::TarotCard, 'image');
            $this->fail('O normalizador decodificou uma imagem acima do teto de pixels.');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey('image', $exception->errors());
            $this->assertStringContainsString('resolução alta demais', $exception->errors()['image'][0]);
        }
    }

    public function test_accepts_a_real_png_and_reports_its_dimensions(): void
    {
        $file = UploadedFile::fake()->createWithContent(
            'pixel.png',
            base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mP8z8BQDwAEhQGAhKmMIQAAAABJRU5ErkJggg==', true),
        );

        $result = (new ImageNormalizer)->normalize($file, ImageProfile::SiteLogo, 'logo');

        $this->assertSame('image/png', $result->mime);
        $this->assertSame('png', $result->extension);
        $this->assertSame(1, $result->width);
        $this->assertSame(1, $result->height);
        $this->assertGreaterThan(0, $result->bytes());
    }

    #[RequiresPhpExtension('gd')]
    public function test_resizes_and_reencodes_card_images_to_webp(): void
    {
        $file = UploadedFile::fake()->image('foto.jpg', 2000, 3000);

        $result = (new ImageNormalizer)->normalize($file, ImageProfile::TarotCard, 'image');

        $this->assertSame('image/webp', $result->mime);
        $this->assertSame('webp', $result->extension);
        $this->assertSame(1200, $result->width);
        $this->assertSame(1800, $result->height);
        $this->assertLessThan((int) $file->getSize(), $result->bytes());

        $size = getimagesizefromstring($result->binary);

        $this->assertIsArray($size);
        $this->assertSame(1200, $size[0]);
        $this->assertSame(1800, $size[1]);
        $this->assertSame('image/webp', $size['mime']);
    }

    public function test_cms_profiles_never_produce_webp_files(): void
    {
        $this->assertSame('png', ImageProfile::SiteFavicon->outputFormat());
        $this->assertSame('png', ImageProfile::SiteLogo->outputFormat('image/jpeg'));
        $this->assertSame('png', ImageProfile::SiteLogo->outputFormat('image/webp'));
        $this->assertSame('png', ImageProfile::SiteSection->outputFormat('image/png'));
        $this->assertSame('jpeg', ImageProfile::SiteSection->outputFormat('image/jpeg'));
        $this->assertSame('jpeg', ImageProfile::SiteSection->outputFormat('image/webp'));
        $this->assertSame('jpeg', ImageProfile::SiteSection->outputFormat());

        $this->assertTrue(ImageProfile::SiteLogo->storesOnlyPngOrJpeg());
        $this->assertTrue(ImageProfile::SiteFavicon->storesOnlyPngOrJpeg());
        $this->assertTrue(ImageProfile::SiteSection->storesOnlyPngOrJpeg());
        $this->assertFalse(ImageProfile::TarotCard->storesOnlyPngOrJpeg());
        $this->assertFalse(ImageProfile::AttendantPhoto->storesOnlyPngOrJpeg());

        $this->assertTrue(ImageProfile::SiteSection->preservesPngSource());
        $this->assertFalse(ImageProfile::SiteLogo->preservesPngSource());

        $this->assertSame(512, ImageProfile::SiteFavicon->maxWidth());
        $this->assertSame(512, ImageProfile::SiteFavicon->maxHeight());
    }

    public function test_internal_panel_profiles_keep_webp_as_the_output_format(): void
    {
        $this->assertSame('webp', ImageProfile::TarotCard->outputFormat());
        $this->assertSame('webp', ImageProfile::AttendantPhoto->outputFormat('image/png'));
    }

    #[RequiresPhpExtension('gd')]
    public function test_webp_uploads_for_the_cms_are_converted_instead_of_stored(): void
    {
        $file = UploadedFile::fake()->image('foto.webp', 600, 400);

        $section = (new ImageNormalizer)->normalize($file, ImageProfile::SiteSection, 'image');

        $this->assertSame('image/jpeg', $section->mime);
        $this->assertSame('jpg', $section->extension);
        $this->assertSame('image/jpeg', getimagesizefromstring($section->binary)['mime']);

        $logo = (new ImageNormalizer)->normalize($file, ImageProfile::SiteLogo, 'logo');

        $this->assertSame('image/png', $logo->mime);
        $this->assertSame('png', $logo->extension);
        $this->assertSame('image/png', getimagesizefromstring($logo->binary)['mime']);
    }

    #[RequiresPhpExtension('gd')]
    public function test_section_images_uploaded_as_jpeg_stay_jpeg(): void
    {
        $file = UploadedFile::fake()->image('banner.jpg', 800, 600);

        $result = (new ImageNormalizer)->normalize($file, ImageProfile::SiteSection, 'image');

        $this->assertSame('image/jpeg', $result->mime);
        $this->assertSame('jpg', $result->extension);
        $this->assertSame('image/jpeg', getimagesizefromstring($result->binary)['mime']);
    }

    #[RequiresPhpExtension('gd')]
    public function test_section_images_uploaded_as_png_keep_png(): void
    {
        $file = UploadedFile::fake()->image('banner.png', 800, 600);

        $result = (new ImageNormalizer)->normalize($file, ImageProfile::SiteSection, 'image');

        $this->assertSame('image/png', $result->mime);
        $this->assertSame('png', $result->extension);
    }

    /**
     * Cabeçalho PNG com as dimensões informadas, suficiente para
     * `getimagesize()` sem exigir a extensão GD.
     */
    private function pngHeader(int $width, int $height): string
    {
        $header = 'IHDR'.pack('NNCCCCC', $width, $height, 8, 6, 0, 0, 0);

        return "\x89PNG\r\n\x1a\n".pack('N', 13).$header.pack('N', crc32($header));
    }
}
