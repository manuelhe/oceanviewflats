<div class="min-h-[60vh] flex flex-col items-center justify-center text-center px-4">
    <div class="w-16 h-16 bg-rose-100 text-rose-600 rounded-full flex items-center justify-center mb-4 shadow-sm">
        <svg class="w-8 h-8" fill="none" stroke="currentColor" viewBox="0 0 24 24">
            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 15v2m0 0v2m0-2h2m-2 0H10m2-6V9a4 4 0 00-8 0v2m0 0a2 2 0 00-2 2v6a2 2 0 002 2h12a2 2 0 002-2v-6a2 2 0 00-2-2H4" />
        </svg>
    </div>
    <h1 class="text-2xl font-bold text-gray-900 tracking-tight">403 - Access Restricted</h1>
    <p class="text-sm text-gray-600 mt-2 max-w-md">
        The Audit Log interface is restricted strictly to accounts with <span class="font-semibold text-gray-800">admin</span> or <span class="font-semibold text-gray-800">superadmin</span> roles.
    </p>
    <p class="text-xs text-gray-400 mt-1">
        Current authenticated role: <span class="font-mono text-gray-600 font-semibold"><?= htmlspecialchars((string) ($currentUser['role'] ?? 'viewer'), ENT_QUOTES, 'UTF-8') ?></span>
    </p>
    <div class="mt-6 flex items-center space-x-3">
        <a href="/" class="inline-flex items-center px-4 py-2 border border-transparent text-sm font-medium rounded-lg text-white bg-indigo-600 hover:bg-indigo-700 shadow-sm transition">
            <svg class="w-4 h-4 mr-2" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M3 12l2-2m0 0l7-7 7 7M5 10v10a1 1 0 001 1h3m10-11l2 2m-2-2v10a1 1 0 01-1 1h-3m-6 0a1 1 0 001-1v-4a1 1 0 011-1h2a1 1 0 011 1v4a1 1 0 001 1m-6 0h6" />
            </svg>
            Return to Dashboard
        </a>
    </div>
</div>
