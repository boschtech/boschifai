<template>
    <div class="max-w-3xl">
        <h1 class="text-xl font-bold text-fg-strong mb-1">Billing</h1>
        <p class="text-sm text-fg-muted mb-6">
            Each pipeline step is paid for from this workspace's prepaid credits. 1 credit = US$1.
        </p>

        <p v-if="$route.query.cancelled" class="mb-6 rounded-md border border-border bg-surface-alt p-4 text-sm text-fg" role="status">
            Checkout was cancelled. Nothing was charged.
        </p>

        <p v-if="loading && !billing" class="text-sm text-fg-muted">Loading…</p>
        <p v-else-if="error" class="text-sm text-danger" role="alert">{{ error }}</p>

        <template v-else-if="billing">
            <p v-if="billing.uses_own_ai_key" class="mb-6 rounded-md border border-border bg-surface-alt p-4 text-sm text-fg" data-own-key>
                Runs in this workspace use its own Anthropic API key, so they don't use credits — Anthropic
                bills your account directly. Manage it under
                <router-link :to="{ name: 'settings.ai-provider' }" class="text-primary underline">AI provider</router-link>.
            </p>

            <section class="mb-8 rounded-md border border-border bg-surface p-4" data-balance>
                <p class="text-sm text-fg-muted">Available credits</p>
                <p class="text-2xl font-bold text-fg-strong">{{ money(billing.available_usd) }}</p>
                <p v-if="billing.held_usd !== '0.00'" class="mt-1 text-xs text-fg-muted">
                    Plus {{ money(billing.held_usd) }} reserved for steps that are running now — whatever they don't use comes back.
                </p>
            </section>

            <section class="mb-8">
                <h2 class="text-sm font-semibold text-fg mb-2">Buy credits</h2>

                <p v-if="!packs.length || !providers.length" class="text-sm text-fg-muted" data-not-available>
                    Buying credits isn't available yet. Ask the BoschifAI team to add credits to this workspace.
                </p>

                <template v-else-if="!quote">
                    <div class="mb-3 grid gap-3 sm:grid-cols-3">
                        <button
                            v-for="pack in packs"
                            :key="pack.key"
                            type="button"
                            :data-pack="pack.key"
                            :aria-pressed="String(selectedPack === pack.key)"
                            class="rounded-md border p-4 text-left transition hover:border-primary/50"
                            :class="selectedPack === pack.key ? 'border-primary bg-primary/10' : 'border-border bg-surface'"
                            @click="selectedPack = pack.key"
                        >
                            <span class="block text-lg font-semibold text-fg-strong">{{ pack.credits_usd }} credits</span>
                            <span class="block text-sm text-fg-muted">{{ money(pack.price_usd) }}</span>
                        </button>
                    </div>

                    <div class="flex flex-wrap items-center gap-3">
                        <select-dropdown
                            v-if="providers.length > 1"
                            id="payment-provider"
                            v-model="selectedProvider"
                            :options="providerOptions"
                            aria-label="Pay with"
                        />
                        <span v-else class="text-sm text-fg-muted">Paid with {{ providerLabel(providers[0]) }}</span>
                        <button type="button" :disabled="!selectedPack || busy" :class="primaryButton" @click="buy">
                            {{ busy ? 'Starting checkout…' : 'Buy credits' }}
                        </button>
                    </div>
                </template>

                <!-- Charged in another currency: show the exact amount before leaving the app. -->
                <div v-else class="rounded-md border border-border bg-surface p-4 text-sm text-fg" data-quote>
                    <p class="mb-3">
                        You'll be charged <strong>{{ money(quote.charge, quote.charge_currency) }}</strong> for {{ quote.credits_usd }} credits.
                    </p>
                    <div class="flex gap-3">
                        <button type="button" :class="primaryButton" @click="redirect(quote.redirect_url)">Continue to payment</button>
                        <button type="button" :class="secondaryButton" @click="quote = null">Back</button>
                    </div>
                </div>

                <p v-if="buyError" class="mt-2 text-sm text-danger" role="alert">{{ buyError }}</p>
            </section>

            <section v-if="payments.length">
                <h2 class="text-sm font-semibold text-fg mb-2">Payments</h2>
                <ul class="divide-y divide-border rounded-md border border-border bg-surface">
                    <li v-for="payment in payments" :key="payment.id" class="flex flex-wrap items-center gap-3 px-4 py-3 text-sm" :data-payment="payment.id">
                        <span class="min-w-0 flex-1">
                            <span class="font-medium text-fg">{{ payment.credits_usd }} credits</span>
                            <span class="text-fg-muted"> · {{ money(payment.charge, payment.charge_currency) }} · {{ formatDate(payment.created_at) }}</span>
                        </span>
                        <span :class="statusClass(payment.status)" data-status>{{ statusLabel(payment.status) }}</span>
                    </li>
                </ul>
            </section>
        </template>
    </div>
</template>

<script>
import { mapGetters } from 'vuex';
import SelectDropdown from '../components/SelectDropdown.vue';
import { formatMoney } from '../lib/money';

const PROVIDER_NAMES = { stripe: 'Stripe', paystack: 'Paystack', payfast: 'PayFast' };

const STATUSES = {
    pending: ['Pending', 'text-fg-muted'],
    succeeded: ['Paid', 'text-success'],
    failed: ['Failed', 'text-danger'],
    needs_review: ['Being checked', 'text-warning'],
};

export default {
    name: 'BillingPage',

    components: { SelectDropdown },

    props: {
        // Leaving the app for the provider's checkout — a prop so tests can see where it goes.
        redirect: { type: Function, default: (url) => window.location.assign(url) },
    },

    data() {
        return {
            selectedPack: null,
            selectedProvider: null,
            busy: false,
            buyError: null,
            quote: null,
            primaryButton: 'rounded-sm bg-primary px-4 py-2 text-sm font-semibold text-white transition hover:bg-primary-dark disabled:opacity-50',
            secondaryButton: 'rounded-sm border border-border bg-surface px-4 py-2 text-sm font-medium text-fg transition hover:bg-surface-alt disabled:opacity-50',
        };
    },

    computed: {
        ...mapGetters('billing', ['billing', 'packs', 'providers', 'payments', 'loading', 'error']),

        providerOptions() {
            return this.providers.map((p) => ({ value: p.key, label: this.providerLabel(p) }));
        },
    },

    async created() {
        await this.$store.dispatch('billing/fetchBilling');
        this.selectedProvider = this.providers[0]?.key ?? null;
    },

    methods: {
        async buy() {
            this.busy = true;
            this.buyError = null;
            try {
                const checkout = await this.$store.dispatch('billing/checkout', { pack: this.selectedPack, provider: this.selectedProvider });
                // USD: the price on the pack is what's charged. Anything else: confirm the amount first.
                if (checkout.charge_currency === 'USD') {
                    this.redirect(checkout.redirect_url);
                } else {
                    this.quote = checkout;
                }
            } catch (e) {
                this.buyError = e.response?.data?.message || e.message;
            } finally {
                this.busy = false;
            }
        },

        money: formatMoney,

        providerLabel(provider) {
            const name = PROVIDER_NAMES[provider.key] ?? provider.key;
            return provider.currency === 'USD' ? name : `${name} (${provider.currency})`;
        },

        statusLabel(status) {
            return STATUSES[status]?.[0] ?? status;
        },

        statusClass(status) {
            return `text-xs font-medium ${STATUSES[status]?.[1] ?? 'text-fg-muted'}`;
        },

        formatDate(iso) {
            return new Date(iso).toLocaleDateString();
        },
    },
};
</script>
