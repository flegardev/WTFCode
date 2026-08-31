export const useAccount = (accountId: string) => {
    return { id: accountId, name: process.env.NEXT_PUBLIC_ACCOUNT_NAME ?? 'Fixture' };
};
