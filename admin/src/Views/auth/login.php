<?php
/**
 * @var string|null $error
 * @var string|null $reason
 * @var string|null $csrfToken
 * @var string|null $email
 */
?>
<div class="min-h-[70vh] flex flex-col justify-center py-12 sm:px-6 lg:px-8">
    <div class="sm:mx-auto sm:w-full sm:max-w-md">
        <div class="flex justify-center">
            <div class="w-12 h-12 bg-indigo-600 rounded-xl flex items-center justify-center shadow-lg text-white">
                <svg class="w-7 h-7" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 15v2m-6 4h12a2 2 0 002-2v-6a2 2 0 00-2-2H6a2 2 0 00-2 2v6a2 2 0 002 2zm10-10V7a4 4 0 00-8 0v4h8z"></path>
                </svg>
            </div>
        </div>
        <h2 class="mt-4 text-center text-2xl font-bold tracking-tight text-gray-900">
            Ocean View Flats Admin
        </h2>
        <p class="mt-1 text-center text-sm text-gray-500">
            Sign in to access property management & reservations
        </p>
    </div>

    <div class="mt-8 sm:mx-auto sm:w-full sm:max-w-md">
        <div class="bg-white py-8 px-4 shadow-xl border border-gray-100 sm:rounded-xl sm:px-10">
            <?php if (!empty($reason)): ?>
                <?php if ($reason === 'idle_timeout'): ?>
                    <div class="mb-5 p-3.5 rounded-lg bg-amber-50 border border-amber-200 text-amber-800 text-sm">
                        Your session timed out after 30 minutes of inactivity. Please sign in again.
                    </div>
                <?php elseif ($reason === 'session_expired'): ?>
                    <div class="mb-5 p-3.5 rounded-lg bg-amber-50 border border-amber-200 text-amber-800 text-sm">
                        Your session reached its 8-hour maximum lifetime. Please sign in again.
                    </div>
                <?php elseif ($reason === 'logged_out'): ?>
                    <div class="mb-5 p-3.5 rounded-lg bg-emerald-50 border border-emerald-200 text-emerald-800 text-sm">
                        You have successfully signed out.
                    </div>
                <?php endif; ?>
            <?php endif; ?>

            <?php if (!empty($error)): ?>
                <div class="mb-5 p-3.5 rounded-lg bg-rose-50 border border-rose-200 text-rose-800 text-sm flex items-start space-x-2">
                    <svg class="w-5 h-5 text-rose-500 flex-shrink-0 mt-0.5" fill="currentColor" viewBox="0 0 20 20">
                        <path fill-rule="evenodd" d="M18 10a8 8 0 11-16 0 8 8 0 0116 0zm-7 4a1 1 0 11-2 0 1 1 0 012 0zm-1-9a1 1 0 00-1 1v4a1 1 0 102 0V6a1 1 0 00-1-1z" clip-rule="evenodd"></path>
                    </svg>
                    <span><?= htmlspecialchars($error, ENT_QUOTES, 'UTF-8') ?></span>
                </div>
            <?php endif; ?>

            <form class="space-y-6" action="/login" method="POST">
                <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrfToken ?? '', ENT_QUOTES, 'UTF-8') ?>">

                <div>
                    <label for="email" class="block text-sm font-medium text-gray-700">Email address</label>
                    <div class="mt-1">
                        <input id="email" name="email" type="email" autocomplete="username" required
                               value="<?= htmlspecialchars($email ?? '', ENT_QUOTES, 'UTF-8') ?>"
                               class="appearance-none block w-full px-3 py-2 border border-gray-300 rounded-lg shadow-sm placeholder-gray-400 focus:outline-none focus:ring-2 focus:ring-indigo-500 focus:border-indigo-500 text-sm"
                               placeholder="operator@oceanviewflats.com">
                    </div>
                </div>

                <div>
                    <label for="password" class="block text-sm font-medium text-gray-700">Password</label>
                    <div class="mt-1">
                        <input id="password" name="password" type="password" autocomplete="current-password" required
                               class="appearance-none block w-full px-3 py-2 border border-gray-300 rounded-lg shadow-sm placeholder-gray-400 focus:outline-none focus:ring-2 focus:ring-indigo-500 focus:border-indigo-500 text-sm">
                    </div>
                </div>

                <div>
                    <button type="submit"
                            class="w-full flex justify-center py-2.5 px-4 border border-transparent rounded-lg shadow-sm text-sm font-medium text-white bg-indigo-600 hover:bg-indigo-700 focus:outline-none focus:ring-2 focus:ring-offset-2 focus:ring-indigo-500 transition duration-150">
                        Sign In
                    </button>
                </div>
            </form>
        </div>
    </div>
</div>
