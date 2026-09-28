/** Payment details the server needs to verify a completed gateway checkout. */
export type GatewayPaymentResult = {
    transaction_id: string;
    order_id?: string;
    signature?: string;
};

/** Order data returned by the server's "initiate" endpoint for a gateway. */
export type GatewayOrder = {
    order_id?: string;
    key_id?: string;
    amount?: number;
    currency?: string;
    payment_session_id?: string;
    mode?: string;
    booking_ref_id?: string;
    message?: string;
};

export function loadExternalScript(src: string): Promise<boolean> {
    return new Promise((resolve) => {
        if (document.querySelector(`script[src="${src}"]`)) {
            resolve(true);

            return;
        }

        const script = document.createElement('script');
        script.src = src;
        script.async = true;
        script.onload = () => resolve(true);
        script.onerror = () => resolve(false);
        document.head.appendChild(script);
    });
}

/**
 * Opens Razorpay Checkout. Resolves with the payment on success, null when the
 * guest closes the window, and rejects with a message when the payment fails.
 */
export async function payWithRazorpay(
    order: GatewayOrder,
    details: {
        name: string;
        description: string;
        prefill: {
            name?: string | null;
            email?: string | null;
            contact?: string | null;
        };
    },
): Promise<GatewayPaymentResult | null> {
    if (
        !(await loadExternalScript(
            'https://checkout.razorpay.com/v1/checkout.js',
        )) ||
        !(window as any).Razorpay
    ) {
        throw new Error(
            'Failed to load the Razorpay payment window. Please check your internet connection.',
        );
    }

    return new Promise((resolve, reject) => {
        const razorpay = new (window as any).Razorpay({
            key: order.key_id,
            amount: order.amount,
            currency: order.currency || 'INR',
            name: details.name,
            description: details.description,
            order_id: order.order_id,
            prefill: details.prefill,
            theme: { color: '#4a7c59' },
            handler: (response: any) =>
                resolve({
                    transaction_id: response.razorpay_payment_id,
                    order_id: response.razorpay_order_id || order.order_id,
                    signature: response.razorpay_signature,
                }),
            modal: { ondismiss: () => resolve(null) },
        });

        razorpay.on('payment.failed', (response: any) =>
            reject(
                new Error(
                    response.error?.description ||
                        'The Razorpay payment was unsuccessful.',
                ),
            ),
        );
        razorpay.open();
    });
}

/** Opens the Cashfree checkout modal; same contract as payWithRazorpay. */
export async function payWithCashfree(
    order: GatewayOrder,
): Promise<GatewayPaymentResult | null> {
    if (!order.payment_session_id) {
        throw new Error(
            order.message || 'The Cashfree payment session is missing.',
        );
    }

    if (
        !(await loadExternalScript(
            'https://sdk.cashfree.com/js/v3/cashfree.js',
        )) ||
        !(window as any).Cashfree
    ) {
        throw new Error(
            'Failed to load the Cashfree payment window. Please check your internet connection.',
        );
    }

    const cashfree = (window as any).Cashfree({
        mode: order.mode === 'production' ? 'production' : 'sandbox',
    });
    const result = await cashfree.checkout({
        paymentSessionId: order.payment_session_id,
        redirectTarget: '_modal',
    });

    if (result.error) {
        throw new Error(result.error.message || 'The Cashfree payment failed.');
    }

    if (!result.paymentDetails) {
        return null;
    }

    return {
        transaction_id:
            result.paymentDetails.cfPaymentId ||
            result.paymentDetails.paymentMessage ||
            `CF_${Date.now()}`,
        order_id: order.order_id,
    };
}
