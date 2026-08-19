import { useAccount } from '../hooks/useAccount';

export function AccountCard({ accountId }: { accountId: string }) {
    const account = useAccount(accountId);
    return <article>{account.name}</article>;
}
