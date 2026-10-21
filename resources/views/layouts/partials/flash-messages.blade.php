@if (session('success'))
    <x-ui.alert type="success" class="mb-3">{{ session('success') }}</x-ui.alert>
@endif

@if (session('error'))
    <x-ui.alert type="danger" class="mb-3">{{ session('error') }}</x-ui.alert>
@endif

@if (session('warning'))
    <x-ui.alert type="warning" class="mb-3">{{ session('warning') }}</x-ui.alert>
@endif

@if (session('info'))
    <x-ui.alert type="info" class="mb-3">{{ session('info') }}</x-ui.alert>
@endif

@if ($errors->any())
    <x-ui.alert type="danger" class="mb-3">
        <ul class="mb-0 ps-3">
            @foreach ($errors->all() as $error)
                <li>{{ $error }}</li>
            @endforeach
        </ul>
    </x-ui.alert>
@endif

