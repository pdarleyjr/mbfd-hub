import { useEffect, useRef, useState } from 'react'
import { sendIssue } from '../../../hub-support/client.js'
import '../../../hub-support/widget.css'

export default function HubIssueWidget() {
  const dialog = useRef<HTMLDialogElement>(null)
  const trigger = useRef<HTMLAnchorElement>(null)
  const confirmation = useRef<HTMLElement>(null)
  const [description, setDescription] = useState('')
  const [attachments, setAttachments] = useState<File[]>([])
  const [submissionId, setSubmissionId] = useState(() => crypto.randomUUID())
  const [sending, setSending] = useState(false)
  const [failed, setFailed] = useState(false)
  const [reference, setReference] = useState<string | null>(null)

  useEffect(() => {
    if (reference) confirmation.current?.focus()
  }, [reference])

  function addFiles(files: FileList | File[] | null) {
    if (!files) return
    const accepted = Array.from(files).filter((file) =>
      ['image/png', 'image/jpeg', 'application/pdf'].includes(file.type) && file.size <= 10 * 1024 * 1024)
    setAttachments((current) => [...current, ...accepted].slice(0, 2))
  }

  async function submit(event: React.FormEvent<HTMLFormElement>) {
    event.preventDefault()
    setSending(true)
    setFailed(false)
    try {
      const result = await sendIssue({ description, attachments, clientSubmissionId: submissionId })
      setReference(result.report.reference)
      setSubmissionId(crypto.randomUUID())
    } catch {
      setFailed(true)
    } finally {
      setSending(false)
    }
  }

  function close() {
    dialog.current?.close()
  }

  function done() {
    close()
  }

  function handleClose() {
    trigger.current?.focus()
    if (!reference) return
    setDescription('')
    setAttachments([])
    setReference(null)
    setFailed(false)
  }

  return <div className="hub-issue-widget hub-issue-widget--daily">
    <a ref={trigger} href="/support/issues/create" className="hub-issue-trigger" onClick={(event) => {
      event.preventDefault()
      dialog.current?.showModal()
    }}>Report an Issue</a>
    <dialog ref={dialog} className="hub-issue-dialog" aria-labelledby="hub-daily-issue-title" onClose={handleClose}
      onPaste={(event) => addFiles(event.clipboardData.files)}
      onDragOver={(event) => event.preventDefault()} onDrop={(event) => { event.preventDefault(); addFiles(event.dataTransfer.files) }}>
      <h2 id="hub-daily-issue-title">Report an Issue</h2>
      {reference ? <section ref={confirmation} role="status" tabIndex={-1}>
        <p>Thanks — we got it.</p>
        <p>We included the page and technical information that may help us track down the problem.</p>
        <p>Reference: {reference}</p>
        <div className="hub-issue-actions"><button type="button" className="hub-issue-secondary" onClick={done}>Done</button>
          <a className="hub-issue-primary" href="/support/issues">View My Reports</a></div>
      </section> : <form onSubmit={submit}>
        <label htmlFor="hub-daily-issue-description">What went wrong?</label>
        <textarea id="hub-daily-issue-description" required maxLength={10000} value={description}
          onChange={(event) => setDescription(event.target.value)}
          placeholder={'Tell us what happened.\n\nExample: I tapped Submit but nothing happened.'} />
        <label className="hub-issue-file" htmlFor="hub-daily-issue-files">+ Add screenshot or file
          <input type="file" id="hub-daily-issue-files" multiple accept="image/png,image/jpeg,application/pdf"
            onChange={(event) => addFiles(event.target.files)} /></label>
        <span aria-live="polite">{attachments.map((file) => file.name).join(', ')}</span>
        <p>We’ll automatically include the page you’re on and safe technical details that may help us find the problem.</p>
        {failed && <p className="hub-issue-error" role="alert">We couldn’t send this yet. Your report is still here.</p>}
        <div className="hub-issue-actions">
          <button type="button" className="hub-issue-secondary" onClick={close}>Cancel</button>
          <button type="submit" className="hub-issue-primary" disabled={sending}>{failed ? 'Try Again' : 'Send'}</button>
        </div>
      </form>}
    </dialog>
  </div>
}
