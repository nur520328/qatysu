import { Navigate, Route, Routes } from 'react-router-dom'
import { useTranslation } from 'react-i18next'
import { useAuth } from './auth/context'
import { ChangePasswordPage } from './pages/ChangePasswordPage'
import { HomePage } from './pages/HomePage'
import { LoginPage } from './pages/LoginPage'
import { DutyApp } from './pages/duty/DutyApp'
import { DutyPage } from './pages/admin/DutyPage'
import { AdminLayout } from './pages/admin/AdminLayout'
import { CalendarPage } from './pages/admin/CalendarPage'
import { ClassPage } from './pages/admin/ClassPage'
import { ClassesPage } from './pages/admin/ClassesPage'
import { ImportStudentsPage } from './pages/admin/ImportStudentsPage'
import { ImportTeachersPage } from './pages/admin/ImportTeachersPage'
import { SettingsPage } from './pages/admin/SettingsPage'
import { UsersPage } from './pages/admin/UsersPage'

export function App() {
  const { t } = useTranslation()
  const { state } = useAuth()

  if (state.status === 'loading') {
    return (
      <p className="splash" role="status">
        {t('common.loading')}
      </p>
    )
  }
  if (state.status === 'guest') {
    // Кезекші оқушының аккаунты жоқ: ол бөлек мекенжайда күндік кодпен кіреді.
    return (
      <Routes>
        <Route path="/kezekshi" element={<DutyApp />} />
        <Route path="*" element={<LoginPage />} />
      </Routes>
    )
  }
  // Бір реттік парольмен кірген адам оны ауыстырмайынша басқа бетке өте алмайды.
  if (state.user.must_change_password) return <ChangePasswordPage />

  // Беттерді жасыру қорғаныс емес: әр сұранысты сервер рөл бойынша өзі тексереді.
  if (state.user.role !== 'admin') {
    return (
      <Routes>
        <Route path="/" element={<HomePage user={state.user} />} />
        <Route path="*" element={<Navigate to="/" replace />} />
      </Routes>
    )
  }

  return (
    <Routes>
      <Route path="/admin" element={<AdminLayout />}>
        <Route path="classes" element={<ClassesPage />} />
        <Route path="classes/:id" element={<ClassPage />} />
        <Route path="import/students" element={<ImportStudentsPage />} />
        <Route path="import/teachers" element={<ImportTeachersPage />} />
        <Route path="duty" element={<DutyPage />} />
        <Route path="users" element={<UsersPage />} />
        <Route path="calendar" element={<CalendarPage />} />
        <Route path="settings" element={<SettingsPage />} />
        <Route index element={<Navigate to="classes" replace />} />
      </Route>
      <Route path="*" element={<Navigate to="/admin/classes" replace />} />
    </Routes>
  )
}
