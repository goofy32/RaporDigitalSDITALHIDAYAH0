<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>500 - SD IT Al-Hidayah</title>
    <script src="https://cdn.tailwindcss.com"></script>
</head>
<body class="min-h-screen bg-gray-50 flex items-center justify-center px-4 py-10">
    <div class="w-full max-w-md mx-auto text-center">
        <div class="bg-white rounded-3xl shadow-lg border border-gray-100 p-8 sm:p-10">
            <img src="{{ asset('images/logo.png') }}"
                alt="Logo SD IT Al-Hidayah Logam"
                class="h-20 w-auto mx-auto mb-6"
                onerror="this.style.display='none'">

            <p class="text-sm font-semibold tracking-[0.2em] text-green-600 uppercase mb-3">
                SD IT Al-Hidayah Logam
            </p>

            <h1 class="text-7xl sm:text-8xl font-bold text-orange-500 leading-none mb-4">
                500
            </h1>

            <h2 class="text-2xl font-semibold text-gray-800 mb-3">
                Terjadi Kesalahan
            </h2>

            <p class="text-gray-500 leading-relaxed mb-8">
                Server mengalami gangguan. Silakan coba beberapa saat lagi atau hubungi administrator.
            </p>

            <div class="flex flex-col sm:flex-row gap-3 justify-center">
                <button type="button"
                    onclick="location.reload()"
                    class="px-6 py-3 rounded-xl border border-green-600 text-green-600 font-medium hover:bg-green-50 transition">
                    Coba Lagi
                </button>
                <button type="button"
                    onclick="history.back()"
                    class="px-6 py-3 rounded-xl bg-green-600 text-white font-medium hover:bg-green-700 transition">
                    Kembali
                </button>
            </div>
        </div>
    </div>
</body>
</html>
