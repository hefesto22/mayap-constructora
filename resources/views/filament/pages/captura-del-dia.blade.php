<x-filament-panels::page>
    <form wire:submit="guardar" class="space-y-6">
        {{ $this->form }}

        <div class="flex justify-end gap-3">
            <x-filament::button type="submit" size="lg" wire:loading.attr="disabled">
                <span wire:loading.remove wire:target="guardar">Guardar todo el día</span>
                <span wire:loading wire:target="guardar">Registrando…</span>
            </x-filament::button>
        </div>
    </form>
</x-filament-panels::page>
