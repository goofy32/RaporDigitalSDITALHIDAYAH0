@php
    $guru = $currentGuru ?? Auth::guard('guru')->user();
    $roles = $currentGuruAvailableRoles ?? [];
    $currentRole = session('selected_role', 'pengajar');
    $roleLabels = [
        'pengajar' => 'Pengajar',
        'wali_kelas' => 'Wali Kelas',
    ];
@endphp

@if($guru && count($roles) > 0)
    <div class="mb-4 rounded-lg border border-gray-200 bg-gray-50 p-3 text-sm">
        <p class="text-xs font-medium uppercase tracking-wide text-gray-500">Peran saat ini</p>
        <p class="mt-1 font-semibold text-gray-800">{{ $roleLabels[$currentRole] ?? 'Pengajar' }}</p>

        @if(count($roles) > 1)
            <div class="mt-3 space-y-2">
                @foreach($roles as $role)
                    @if($role !== $currentRole)
                        <form method="POST"
                              action="{{ route('auth.switch.role', ['role' => $role]) }}"
                              data-turbo="false"
                              data-turbo-prefetch="false"
                              onsubmit="this.querySelector('[data-role-switch-submit]').disabled = true">
                            @csrf
                            <button type="submit"
                                    data-role-switch-submit
                                    class="block w-full rounded-md border border-green-600 px-3 py-2 text-center text-xs font-semibold text-green-700 transition-colors hover:bg-green-50 disabled:cursor-wait disabled:opacity-70">
                                Beralih ke {{ $roleLabels[$role] }}
                            </button>
                        </form>
                    @endif
                @endforeach
            </div>
        @endif
    </div>
@endif
