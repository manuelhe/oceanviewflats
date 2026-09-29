<?php
/**
 * @var string|null $errorMessage
 */
?>

<div class="fixed inset-0 z-50 overflow-y-auto" aria-labelledby="modal-title" role="dialog" aria-modal="true">
    <!-- Backdrop -->
    <div class="fixed inset-0 bg-gray-900/60 backdrop-blur-xs transition-opacity"
         onclick="document.getElementById('modal-container').innerHTML = '';"
         aria-hidden="true"></div>

    <div class="min-h-full flex items-center justify-center p-4 text-center sm:p-0">
        <div class="relative bg-white rounded-2xl text-left overflow-hidden shadow-2xl transform transition-all sm:my-8 sm:max-w-2xl sm:w-full border border-gray-200">
            
            <!-- Modal Header -->
            <div class="px-6 py-4 bg-gray-50 border-b border-gray-200 flex items-center justify-between">
                <div class="flex items-center space-x-3">
                    <div class="w-8 h-8 rounded-lg bg-indigo-100 text-indigo-700 flex items-center justify-center">
                        <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 4v16m8-8H4" />
                        </svg>
                    </div>
                    <div>
                        <h3 class="text-base font-bold text-gray-900" id="modal-title">
                            Create Manual Reservation
                        </h3>
                        <p class="text-xs text-gray-500">
                            Book direct stays, bank wires, or owner occupancies with payment gateway bypass.
                        </p>
                    </div>
                </div>
                <button type="button"
                        onclick="document.getElementById('modal-container').innerHTML = '';"
                        class="text-gray-400 hover:text-gray-600 rounded-lg p-1 hover:bg-gray-100 transition cursor-pointer">
                    <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12" />
                    </svg>
                </button>
            </div>

            <!-- Modal Body Form -->
            <form id="create-reservation-form"
                  hx-post="/reservations/create-manual"
                  hx-target="#modal-container"
                  class="p-6 space-y-4">

                <?php if (!empty($errorMessage)): ?>
                    <div class="p-3 bg-rose-50 border border-rose-200 rounded-xl text-xs text-rose-800 flex items-center">
                        <svg class="w-4 h-4 mr-2 text-rose-600 flex-shrink-0" fill="currentColor" viewBox="0 0 20 20">
                            <path fill-rule="evenodd" d="M10 18a8 8 0 100-16 8 8 0 000 16zM8.707 7.293a1 1 0 00-1.414 1.414L8.586 10l-1.293 1.293a1 1 0 101.414 1.414L10 11.414l1.293 1.293a1 1 0 001.414-1.414L11.414 10l1.293-1.293a1 1 0 00-1.414-1.414L10 8.586 8.707 7.293z" clip-rule="evenodd"></path>
                        </svg>
                        <span><?= htmlspecialchars($errorMessage, ENT_QUOTES, 'UTF-8') ?></span>
                    </div>
                <?php endif; ?>

                <!-- Property & Channel Source -->
                <div class="grid grid-cols-1 sm:grid-cols-2 gap-4">
                    <div>
                        <label for="create-property-id" class="block text-xs font-semibold text-gray-700 uppercase tracking-wider mb-1">
                            Property *
                        </label>
                        <select name="property_id"
                                id="create-property-id"
                                required
                                hx-post="/reservations/quote-preview"
                                hx-trigger="change"
                                hx-target="#quote-preview-container"
                                hx-include="#create-reservation-form"
                                class="w-full text-xs bg-gray-50 border border-gray-300 rounded-lg px-3 py-2 text-gray-900 focus:bg-white focus:outline-none focus:ring-2 focus:ring-indigo-500">
                            <option value="1606">Oceanview Deluxe 1606 (16th Fl)</option>
                            <option value="1707">Oceanview Grand 1707 (17th Fl)</option>
                        </select>
                    </div>

                    <div>
                        <label for="create-source" class="block text-xs font-semibold text-gray-700 uppercase tracking-wider mb-1">
                            Booking Source *
                        </label>
                        <select name="source"
                                id="create-source"
                                required
                                hx-post="/reservations/quote-preview"
                                hx-trigger="change"
                                hx-target="#quote-preview-container"
                                hx-include="#create-reservation-form"
                                class="w-full text-xs bg-gray-50 border border-gray-300 rounded-lg px-3 py-2 text-gray-900 focus:bg-white focus:outline-none focus:ring-2 focus:ring-indigo-500">
                            <option value="bank_transfer">Direct Bank Transfer / Wire</option>
                            <option value="cash">Cash / In-Person</option>
                            <option value="owner_stay">Owner Occupancy ($0.00)</option>
                            <option value="manual_override">Administrative Manual Override</option>
                        </select>
                    </div>
                </div>

                <!-- Check-in & Check-out Dates -->
                <div class="grid grid-cols-1 sm:grid-cols-2 gap-4">
                    <div>
                        <label for="create-check-in" class="block text-xs font-semibold text-gray-700 uppercase tracking-wider mb-1">
                            Check-in Date *
                        </label>
                        <input type="date"
                               name="check_in"
                               id="create-check-in"
                               required
                               hx-post="/reservations/quote-preview"
                               hx-trigger="change"
                               hx-target="#quote-preview-container"
                               hx-include="#create-reservation-form"
                               class="w-full text-xs bg-gray-50 border border-gray-300 rounded-lg px-3 py-2 text-gray-900 focus:bg-white focus:outline-none focus:ring-2 focus:ring-indigo-500">
                    </div>

                    <div>
                        <label for="create-check-out" class="block text-xs font-semibold text-gray-700 uppercase tracking-wider mb-1">
                            Check-out Date *
                        </label>
                        <input type="date"
                               name="check_out"
                               id="create-check-out"
                               required
                               hx-post="/reservations/quote-preview"
                               hx-trigger="change"
                               hx-target="#quote-preview-container"
                               hx-include="#create-reservation-form"
                               class="w-full text-xs bg-gray-50 border border-gray-300 rounded-lg px-3 py-2 text-gray-900 focus:bg-white focus:outline-none focus:ring-2 focus:ring-indigo-500">
                    </div>
                </div>

                <!-- Real-time Quote & Availability Preview Container -->
                <div id="quote-preview-container" class="transition-all">
                    <p class="text-[11px] text-gray-400 italic">Select property and dates above to check live availability and rate quote.</p>
                </div>

                <!-- Total Price (Editable Override) -->
                <div>
                    <div class="flex justify-between items-center mb-1">
                        <label for="manual-total-price" class="block text-xs font-semibold text-gray-700 uppercase tracking-wider">
                            Total Price (COP) *
                        </label>
                        <span class="text-[10px] text-gray-400">Pre-calculated via QuoteEngine, editable</span>
                    </div>
                    <div class="relative rounded-md shadow-2xs">
                        <div class="absolute inset-y-0 left-0 pl-3 flex items-center pointer-events-none text-gray-400 text-xs font-bold">
                            $
                        </div>
                        <input type="number"
                               step="1000"
                               min="0"
                               name="total_price"
                               id="manual-total-price"
                               required
                               placeholder="0"
                               oninput="this.dataset.autoFilled = 'false';"
                               class="w-full pl-7 pr-16 py-2 text-xs bg-gray-50 border border-gray-300 rounded-lg text-gray-900 font-semibold focus:bg-white focus:outline-none focus:ring-2 focus:ring-indigo-500">
                        <div class="absolute inset-y-0 right-0 pr-3 flex items-center pointer-events-none text-gray-400 text-[11px]">
                            COP
                        </div>
                    </div>
                </div>

                <!-- Primary Guest Details -->
                <div class="pt-2 border-t border-gray-200">
                    <h4 class="text-xs font-bold uppercase tracking-wider text-gray-400 mb-3">Guest Information</h4>
                    <div class="grid grid-cols-1 sm:grid-cols-3 gap-3">
                        <div class="sm:col-span-1">
                            <label for="create-guest-name" class="block text-xs text-gray-600 mb-1 font-medium">Full Name *</label>
                            <input type="text"
                                   name="guest_name"
                                   id="create-guest-name"
                                   required
                                   placeholder="e.g. Maria Gonzalez"
                                   class="w-full text-xs bg-gray-50 border border-gray-300 rounded-lg px-3 py-2 text-gray-900 focus:bg-white focus:outline-none focus:ring-2 focus:ring-indigo-500">
                        </div>

                        <div class="sm:col-span-1">
                            <label for="create-guest-email" class="block text-xs text-gray-600 mb-1 font-medium">Email Address *</label>
                            <input type="email"
                                   name="guest_email"
                                   id="create-guest-email"
                                   required
                                   placeholder="guest@example.com"
                                   class="w-full text-xs bg-gray-50 border border-gray-300 rounded-lg px-3 py-2 text-gray-900 focus:bg-white focus:outline-none focus:ring-2 focus:ring-indigo-500">
                        </div>

                        <div class="sm:col-span-1">
                            <label for="create-guest-phone" class="block text-xs text-gray-600 mb-1 font-medium">Phone Number *</label>
                            <input type="tel"
                                   name="guest_phone"
                                   id="create-guest-phone"
                                   required
                                   placeholder="+57 300 123 4567"
                                   class="w-full text-xs bg-gray-50 border border-gray-300 rounded-lg px-3 py-2 text-gray-900 focus:bg-white focus:outline-none focus:ring-2 focus:ring-indigo-500">
                        </div>
                    </div>
                </div>

                <!-- Operational Notes -->
                <div>
                    <label for="create-notes" class="block text-xs text-gray-600 mb-1 font-medium">Internal Operational Notes</label>
                    <textarea name="notes"
                              id="create-notes"
                              rows="2"
                              placeholder="Wire reference number, reason for manual override, or guest requests..."
                              class="w-full text-xs bg-gray-50 border border-gray-300 rounded-lg p-2.5 text-gray-900 focus:bg-white focus:outline-none focus:ring-2 focus:ring-indigo-500"></textarea>
                </div>

                <!-- Operational Toggles -->
                <div class="p-3.5 bg-gray-50 rounded-xl border border-gray-200 space-y-2">
                    <label class="flex items-start space-x-2.5 cursor-pointer">
                        <input type="checkbox"
                               name="pre_mark_registry"
                               value="1"
                               class="mt-0.5 rounded text-indigo-600 focus:ring-indigo-500 h-4 w-4 border-gray-300">
                        <div class="text-xs">
                            <span class="font-medium text-gray-900">Pre-mark Guest Registry as completed</span>
                            <p class="text-[11px] text-gray-500">Check this if identity documents were already physically verified out-of-band.</p>
                        </div>
                    </label>

                    <label class="flex items-start space-x-2.5 cursor-pointer">
                        <input type="checkbox"
                               name="send_confirmation_email"
                               value="1"
                               checked
                               class="mt-0.5 rounded text-indigo-600 focus:ring-indigo-500 h-4 w-4 border-gray-300">
                        <div class="text-xs">
                            <span class="font-medium text-gray-900">Send confirmation email to guest</span>
                            <p class="text-[11px] text-gray-500">Dispatches localized confirmation email with direct link to online Guest Registry.</p>
                        </div>
                    </label>
                </div>

                <!-- Modal Footer -->
                <div class="pt-3 border-t border-gray-200 flex items-center justify-end space-x-3">
                    <button type="button"
                            onclick="document.getElementById('modal-container').innerHTML = '';"
                            class="px-4 py-2 border border-gray-300 rounded-lg shadow-2xs text-xs font-medium text-gray-700 bg-white hover:bg-gray-50 transition cursor-pointer">
                        Cancel
                    </button>
                    <button type="submit"
                            class="px-5 py-2 border border-transparent rounded-lg shadow-2xs text-xs font-semibold text-white bg-indigo-600 hover:bg-indigo-700 transition cursor-pointer flex items-center">
                        <span class="htmx-indicator mr-2 hidden">
                            <svg class="animate-spin h-3.5 w-3.5 text-white" fill="none" viewBox="0 0 24 24">
                                <circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"></circle>
                                <path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4zm2 5.291A7.962 7.962 0 014 12H0c0 3.042 1.135 5.824 3 7.938l3-2.647z"></path>
                            </svg>
                        </span>
                        Create Reservation
                    </button>
                </div>

            </form>

        </div>
    </div>
</div>
