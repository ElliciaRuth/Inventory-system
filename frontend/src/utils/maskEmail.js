/**
 * Partially hides an email for display, keeping enough to recognise it:
 *   manager_fpc@bsu.edu.ph → ma********c@bsu.edu.ph
 *   ana@gmail.com          → a**@gmail.com
 */
export function maskEmail(email) {
  if (!email || typeof email !== 'string') return email
  const at = email.lastIndexOf('@')
  if (at < 1) return email

  const local = email.slice(0, at)
  const domain = email.slice(at)
  if (local.length <= 3) {
    return local[0] + '*'.repeat(local.length - 1) + domain
  }
  return local.slice(0, 2) + '*'.repeat(local.length - 3) + local.slice(-1) + domain
}
