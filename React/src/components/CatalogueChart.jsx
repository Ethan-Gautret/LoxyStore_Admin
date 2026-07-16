import { useEffect, useRef, useState } from 'react'

// Série unique (taille du catalogue dans le temps) → une seule couleur d'accent,
// pas de légende (le titre nomme la série), grille discrète, hover + tooltip.
const ACCENT = '#1e7fff'
const H = 300
const PAD = { l: 44, r: 16, t: 16, b: 34 }

// Pas d'axe "propre" (1, 2, 5, 10, 20…) pour ~`target` intervalles → graduations
// toujours entières (pas de 2,5 arrondi en 3).
function niceStep(range, target) {
  const raw = Math.max(1, range / target)
  const pow = Math.pow(10, Math.floor(Math.log10(raw)))
  const n = raw / pow
  const step = n <= 1 ? 1 : n <= 2 ? 2 : n <= 5 ? 5 : 10
  return step * pow
}

function fmtDate(iso) {
  const [, m, d] = iso.split('-')
  return `${d}/${m}`
}

export default function CatalogueChart({ series }) {
  const wrapRef = useRef(null)
  const [w, setW] = useState(720)
  const [hover, setHover] = useState(null)

  useEffect(() => {
    if (!wrapRef.current) return
    const ro = new ResizeObserver((entries) => {
      const cw = entries[0].contentRect.width
      if (cw) setW(Math.max(280, Math.floor(cw)))
    })
    ro.observe(wrapRef.current)
    return () => ro.disconnect()
  }, [])

  const n = series.length
  if (n === 0) return null

  const plotW = Math.max(1, w - PAD.l - PAD.r)
  const plotH = H - PAD.t - PAD.b
  const maxTotal = Math.max(1, ...series.map((s) => s.total))
  const step = niceStep(maxTotal, 4)
  const yMax = Math.ceil(maxTotal / step) * step

  const xAt = (i) => (n <= 1 ? PAD.l + plotW / 2 : PAD.l + (i / (n - 1)) * plotW)
  const yAt = (v) => PAD.t + plotH - (v / yMax) * plotH

  const pts = series.map((s, i) => `${xAt(i).toFixed(1)},${yAt(s.total).toFixed(1)}`)
  const linePath = 'M' + pts.join(' L')
  const areaPath = `M${xAt(0).toFixed(1)},${(PAD.t + plotH).toFixed(1)} L${pts.join(' L')} L${xAt(n - 1).toFixed(1)},${(PAD.t + plotH).toFixed(1)} Z`

  const yTicks = []
  for (let v = 0; v <= yMax; v += step) yTicks.push(v)
  const xTickIdx = [...new Set([0, 1, 2, 3, 4].map((i) => Math.round((i * (n - 1)) / 4)))]

  function onMove(e) {
    const rect = wrapRef.current.getBoundingClientRect()
    const x = e.clientX - rect.left
    let idx = n <= 1 ? 0 : Math.round(((x - PAD.l) / plotW) * (n - 1))
    idx = Math.max(0, Math.min(n - 1, idx))
    setHover(idx)
  }

  const last = series[n - 1]
  const hoverPt = hover != null ? series[hover] : null

  return (
    <div
      ref={wrapRef}
      className="cat-chart"
      style={{ position: 'relative', width: '100%' }}
      onMouseMove={onMove}
      onMouseLeave={() => setHover(null)}
    >
      <svg
        width={w}
        height={H}
        role="img"
        aria-label={`Évolution du catalogue : ${last.total} produits au ${fmtDate(last.date)}, sur ${n} jours.`}
        style={{ display: 'block' }}
      >
        <defs>
          <linearGradient id="catAreaFill" x1="0" y1="0" x2="0" y2="1">
            <stop offset="0%" stopColor={ACCENT} stopOpacity="0.22" />
            <stop offset="100%" stopColor={ACCENT} stopOpacity="0" />
          </linearGradient>
        </defs>

        {/* Grille + libellés Y (discrets) */}
        {yTicks.map((t) => (
          <g key={t}>
            <line x1={PAD.l} y1={yAt(t)} x2={w - PAD.r} y2={yAt(t)} stroke="rgba(31,42,67,0.08)" strokeWidth="1" />
            <text x={PAD.l - 8} y={yAt(t) + 4} textAnchor="end" fontSize="11" fill="#8b97ae">{t}</text>
          </g>
        ))}

        {/* Libellés X */}
        {xTickIdx.map((i) => (
          <text key={i} x={xAt(i)} y={H - PAD.b + 18} textAnchor="middle" fontSize="11" fill="#8b97ae">
            {fmtDate(series[i].date)}
          </text>
        ))}

        {/* Aire + ligne */}
        <path d={areaPath} fill="url(#catAreaFill)" />
        <path d={linePath} fill="none" stroke={ACCENT} strokeWidth="2" strokeLinejoin="round" strokeLinecap="round" />

        {/* Point final mis en avant */}
        <circle cx={xAt(n - 1)} cy={yAt(last.total)} r="3.5" fill={ACCENT} stroke="#fff" strokeWidth="1.5" />

        {/* Curseur de survol */}
        {hoverPt && (
          <g>
            <line x1={xAt(hover)} y1={PAD.t} x2={xAt(hover)} y2={PAD.t + plotH} stroke="rgba(31,42,67,0.25)" strokeWidth="1" strokeDasharray="3 3" />
            <circle cx={xAt(hover)} cy={yAt(hoverPt.total)} r="4.5" fill={ACCENT} stroke="#fff" strokeWidth="2" />
          </g>
        )}
      </svg>

      {hoverPt && (
        <div
          className="cat-chart-tooltip"
          style={{
            left: Math.min(Math.max(xAt(hover), 60), w - 60),
            top: yAt(hoverPt.total) - 8,
          }}
        >
          <strong>{hoverPt.total} produits</strong>
          <span>{fmtDate(hoverPt.date)}{hoverPt.added > 0 ? ` · +${hoverPt.added}` : ''}</span>
        </div>
      )}
    </div>
  )
}
