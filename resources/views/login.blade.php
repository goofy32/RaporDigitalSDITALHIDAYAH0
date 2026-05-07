<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="turbo-cache-control" content="no-cache">
    <link rel="icon" type="image/x-icon" href="{{ asset('favicon.ico') }}">
    <link rel="icon" type="image/png" sizes="16x16" href="{{ asset('favicon-16x16.png') }}">
    <link rel="icon" type="image/png" sizes="32x32" href="{{ asset('favicon-32x32.png') }}">
    <link rel="apple-touch-icon" sizes="180x180" href="{{ asset('apple-touch-icon.png') }}">
    <title>Login - Rapor Digital SDIT Al-Hidayah Logam</title>
    @vite(['resources/css/app.css', 'resources/js/app.js'])
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/flowbite/1.6.5/flowbite.min.css">
    <style>
        body {
            background-color: #f8fafc; /* Background yang bersih */
        }

        .shadow-card {
            box-shadow: 0px 4px 10px rgba(0, 0, 0, 0.1); /* Bayangan lembut */
        }

        input[type="password"]::-ms-reveal,
        input[type="password"]::-ms-clear {
            display: none !important;
        }
        
        /* Untuk Chrome/Safari */
        input[type="password"]::-webkit-contacts-auto-fill-button,
        input[type="password"]::-webkit-credentials-auto-fill-button,
        input[type="password"]::-webkit-password-toggle {
            display: none !important;
            visibility: hidden !important;
            pointer-events: none !important;
            position: absolute !important;
        }
        
        /* Specific override for the role select to match the size of other inputs */
        select {
            font-size: 14px !important; /* Match other inputs */
        }
    </style>
</head>

<body class="flex flex-col items-center justify-center min-h-screen p-4 bg-gray-100">
    <!-- Nama Sekolah -->
    <div class="text-center mb-6">
        <h1 class="text-2xl sm:text-3xl md:text-4xl font-bold text-green-700 mb-4 leading-snug">
            RAPOR DIGITAL <br> SDIT AL-HIDAYAH LOGAM
        </h1>
    </div>

    <!-- Form Login -->
    <div class="bg-white p-8 sm:p-12 rounded-none w-full max-w-xl sm:max-w-md shadow-card">
        <!-- Logo -->
        <div class="flex flex-col items-center mb-4">
            <img src="{{ asset('images/icons/sdit-logo.png') }}" alt="Logo Sekolah"
                class="w-36 h-36 object-contain mb-2">
        </div>

        @if ($errors->any())
        <div class="mb-4 p-4 text-sm text-red-800 rounded-lg bg-red-50">
            <ul class="list-disc list-inside">
                @foreach ($errors->all() as $error)
                    <li>{{ $error }}</li>
                @endforeach
            </ul>
        </div>
        @endif

        <!-- Form -->
        <form action="{{ route('login.post') }}" method="POST">
            @csrf
            <!-- Username -->
            <div class="mb-4">
                <label for="username" class="block mb-2 text-sm font-medium text-gray-700">Username atau Email</label>
                <input type="text" name="username" id="username" required value="{{ old('username') }}"
                    class="w-full px-4 py-2 border border-gray-300 rounded-md focus:outline-none focus:ring-2 focus:ring-green-500">
            </div>

            <!-- Password -->
            <div class="mb-4">
                <label for="password" class="block mb-2 text-sm font-medium text-gray-700">Sandi</label>
                <div class="relative">
                    <input type="password" 
                        name="password" 
                        id="password" 
                        required
                        class="w-full px-4 py-2 pr-12 border border-gray-300 rounded-md focus:outline-none focus:ring-2 focus:ring-green-500">
                    <button type="button" 
                            onclick="togglePasswordVisibility()"
                            class="absolute inset-y-0 right-0 flex items-center px-4 text-gray-600">
                        <svg class="w-5 h-5" 
                            id="showPassword"
                            fill="none" 
                            stroke="currentColor" 
                            viewBox="0 0 24 24">
                            <path stroke-linecap="round" 
                                stroke-linejoin="round" 
                                stroke-width="2" 
                                d="M15 12a3 3 0 11-6 0 3 3 0 016 0z"/>
                            <path stroke-linecap="round" 
                                stroke-linejoin="round" 
                                stroke-width="2" 
                                d="M2.458 12C3.732 7.943 7.523 5 12 5c4.478 0 8.268 2.943 9.542 7-1.274 4.057-5.064 7-9.542 7-4.477 0-8.268-2.943-9.542-7z"/>
                        </svg>
                        <svg class="w-5 h-5 hidden" 
                            id="hidePassword"
                            fill="none" 
                            stroke="currentColor" 
                            viewBox="0 0 24 24">
                            <path stroke-linecap="round" 
                                stroke-linejoin="round" 
                                stroke-width="2" 
                                d="M13.875 18.825A10.05 10.05 0 0112 19c-4.478 0-8.268-2.943-9.543-7a9.97 9.97 0 011.563-3.029m5.858.908a3 3 0 114.243 4.243M9.878 9.878l4.242 4.242M9.88 9.88l-3.29-3.29m7.532 7.532l3.29 3.29M3 3l3.59 3.59m0 0A9.953 9.953 0 0112 5c4.478 0 8.268 2.943 9.543 7a10.025 10.025 0 01-4.132 5.411m0 0L21 21"/>
                        </svg>
                    </button>
                </div>
            </div>
            <!-- Button -->
            <div class="flex justify-center mt-6">
                <button type="submit"
                    class="w-full px-4 py-2 text-white bg-green-700 rounded-lg hover:bg-green-800 focus:outline-none focus:ring-2 focus:ring-green-500">
                    Masuk
                </button>
            </div>
        </form>
    </div>
<script>
function togglePasswordVisibility() {
    const passwordInput = document.getElementById('password');
    const showPasswordIcon = document.getElementById('showPassword');
    const hidePasswordIcon = document.getElementById('hidePassword');
    
    if (passwordInput.type === 'password') {
        passwordInput.type = 'text';
        showPasswordIcon.classList.add('hidden');
        hidePasswordIcon.classList.remove('hidden');
    } else {
        passwordInput.type = 'password';
        showPasswordIcon.classList.remove('hidden');
        hidePasswordIcon.classList.add('hidden');
    }
}
</script>
</body>

</html>
