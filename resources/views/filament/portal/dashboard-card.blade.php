{{-- A portal Dashboard card (App\Portal\DashboardCard). --}}
<x-filament-widgets::widget>
    <x-filament::section>
        <x-slot name="heading">
            @if (filled($url = $this->url()))
                <a href="{{ $url }}" class="hover:underline">{{ $this->heading() }}</a>
            @else
                {{ $this->heading() }}
            @endif
        </x-slot>

        {{ $this->body() }}
    </x-filament::section>
</x-filament-widgets::widget>
