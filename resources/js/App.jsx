import { Routes, Route } from 'react-router-dom';
import AppShell from './components/layout/AppShell';
import DashboardPage from './pages/DashboardPage';
import EmployeesPage from './pages/EmployeesPage';
import EmployeeDetailPage from './pages/EmployeeDetailPage';
import WalletsPage from './pages/WalletsPage';
import WalletDetailPage from './pages/WalletDetailPage';
import TransactionsPage from './pages/TransactionsPage';
import WithdrawalsPage from './pages/WithdrawalsPage';
import PayrollEventsPage from './pages/PayrollEventsPage';
import BankPaymentsPage from './pages/BankPaymentsPage';
import HealthPage from './pages/HealthPage';
import NotFoundPage from './pages/NotFoundPage';

export default function App() {
    return (
        <AppShell>
            <Routes>
                <Route path="/" element={<DashboardPage />} />
                <Route path="/employees" element={<EmployeesPage />} />
                <Route path="/employees/:id" element={<EmployeeDetailPage />} />
                <Route path="/wallets" element={<WalletsPage />} />
                <Route path="/wallets/:id" element={<WalletDetailPage />} />
                <Route path="/transactions" element={<TransactionsPage />} />
                <Route path="/withdrawals" element={<WithdrawalsPage />} />
                <Route path="/payroll-events" element={<PayrollEventsPage />} />
                <Route path="/bank-payments" element={<BankPaymentsPage />} />
                <Route path="/health" element={<HealthPage />} />
                <Route path="*" element={<NotFoundPage />} />
            </Routes>
        </AppShell>
    );
}
