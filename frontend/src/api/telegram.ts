import { request } from './client'

/** Өз аккаунтының ботпен байланысы. Келісім: docs/api.md. */
export interface TelegramState {
  ready: boolean
  bot_username: string | null
  linked: boolean
  linked_at: string | null
}

export interface TelegramLink {
  url: string
  expires_at: string
}

/** Боттың жалпы күйі (тек завучқа). */
export interface TelegramAdminState {
  token_set: boolean
  username: string | null
  webhook_at: string | null
  linked_users: number
  active_users: number
  sent_24h: number
  failed_24h: number
}

export const telegramApi = {
  state: () => request<TelegramState>('GET', '/telegram'),
  createLink: () => request<TelegramLink>('POST', '/telegram/link'),
  unlink: () => request<TelegramState>('DELETE', '/telegram/link'),
  adminState: () => request<TelegramAdminState>('GET', '/admin/telegram'),
  adminSetup: () => request<TelegramAdminState>('POST', '/admin/telegram/setup'),
}
