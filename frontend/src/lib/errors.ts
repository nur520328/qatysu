import { useCallback } from 'react'
import { useTranslation } from 'react-i18next'
import { ApiError } from '../api/client'

/** Сервер қатесінің кодын пайдаланушының тіліндегі мәтінге айналдырады. */
export function useErrorText(): (error: unknown) => string {
  const { t } = useTranslation()

  return useCallback(
    (error: unknown) => {
      const fallback = t('common.errorGeneric')
      return error instanceof ApiError ? t(`errors.${error.code}`, { defaultValue: fallback }) : fallback
    },
    [t],
  )
}

/** Жол қатесінің коды (жүктеу есебінде) үшін мәтін. */
export function useCodeText(): (code: string | null | undefined) => string {
  const { t } = useTranslation()

  return useCallback((code) => (code ? t(`errors.${code}`, { defaultValue: code }) : ''), [t])
}
