<?php

declare(strict_types=1);

use App\Models\BrandingSetting;
use Illuminate\Support\Facades\Storage;

/*
|--------------------------------------------------------------------------
| Branding — limpieza definitiva de archivos huérfanos.
|--------------------------------------------------------------------------
| Al reemplazar o quitar el logo/favicon, el archivo anterior se borra
| del disco. Sin esto, storage/branding acumula archivos muertos para
| siempre (cada intento de subida deja uno).
*/

beforeEach(function (): void {
    Storage::fake('public');
});

test('reemplazar el logo borra definitivamente el archivo anterior', function (): void {
    Storage::disk('public')->put('branding/viejo.webp', 'x');
    Storage::disk('public')->put('branding/nuevo.webp', 'y');

    $setting = BrandingSetting::current();
    $setting->update(['logo_path' => 'branding/viejo.webp']);

    $setting->update(['logo_path' => 'branding/nuevo.webp']);

    Storage::disk('public')->assertMissing('branding/viejo.webp');
    Storage::disk('public')->assertExists('branding/nuevo.webp');
});

test('quitar el favicon borra el archivo del disco', function (): void {
    Storage::disk('public')->put('branding/favicon-abc.png', 'x');

    $setting = BrandingSetting::current();
    $setting->update(['favicon_path' => 'branding/favicon-abc.png']);

    $setting->update(['favicon_path' => null]);

    Storage::disk('public')->assertMissing('branding/favicon-abc.png');
});

test('guardar sin cambiar las imágenes NO borra nada', function (): void {
    Storage::disk('public')->put('branding/logo.webp', 'x');

    $setting = BrandingSetting::current();
    $setting->update(['logo_path' => 'branding/logo.webp']);

    // Cambia solo el color: el logo queda intacto.
    $setting->update(['primary_color' => '#1a1a1a']);

    Storage::disk('public')->assertExists('branding/logo.webp');
});
