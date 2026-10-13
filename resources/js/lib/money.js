/**
 * "US$10.00" for USD (credits are always USD), "ZAR 855.44" for anything else. Amounts arrive
 * from the API as fixed two-decimal strings and are shown as-is — never re-rounded here.
 */
export function formatMoney(amount, currency = 'USD') {
    return currency === 'USD' ? `US$${amount}` : `${currency} ${amount}`;
}
