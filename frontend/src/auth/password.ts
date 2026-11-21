/** Сервердегі ережемен бірдей: кемінде 8 таңба, әріп те, сан да бар. */
export function isStrongPassword(value: string): boolean {
  return value.length >= 8 && /\p{L}/u.test(value) && /\p{N}/u.test(value)
}
