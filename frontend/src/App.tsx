import { useTranslation } from 'react-i18next'
import { useAuth } from './auth/context'
import { ChangePasswordPage } from './pages/ChangePasswordPage'
import { HomePage } from './pages/HomePage'
import { LoginPage } from './pages/LoginPage'

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
  if (state.status === 'guest') return <LoginPage />
  // Бір реттік парольмен кірген адам оны ауыстырмайынша басқа бетке өте алмайды.
  if (state.user.must_change_password) return <ChangePasswordPage />
  return <HomePage user={state.user} />
}
