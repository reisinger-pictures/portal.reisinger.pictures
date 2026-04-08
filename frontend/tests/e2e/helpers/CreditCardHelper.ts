export interface CreditCard {
    number: string;
    exp: string;
    cvc: string;
}

export class CreditCardHelper {
    /**
     * Berechnet ein dynamisches Ablaufdatum in der Zukunft (Aktuelles Jahr + 2 Jahre)
     * Format: MM/YY
     */
    private static getFutureExp(): string {
        const date = new Date();
        const futureYear = (date.getFullYear() + 2).toString().slice(-2);
        let month = (date.getMonth() + 1).toString();
        if (month.length === 1) month = '0' + month;
        return `${month}/${futureYear}`;
    }

    private static getPastExp(): string {
        return '01/22';
    }

    static get successVisa(): CreditCard {
        return { number: '4242424242424242', exp: this.getFutureExp(), cvc: '123' };
    }

    static get genericDecline(): CreditCard {
        return { number: '4000000000000002', exp: this.getFutureExp(), cvc: '123' };
    }

    static get insufficientFunds(): CreditCard {
        return { number: '4000000000000004', exp: this.getFutureExp(), cvc: '123' };
    }

    static get invalidCvc(): CreditCard {
        return { number: '4000000000000127', exp: this.getFutureExp(), cvc: '123' };
    }

    static get expiredCard(): CreditCard {
        return { number: '4242424242424242', exp: this.getPastExp(), cvc: '123' };
    }
}
