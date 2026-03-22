// Typst template partials for MBFD Workgroup Report
// Quarto will use this for PDF rendering via Typst

// Override the default Quarto title block
#show: doc => {
  // Page setup
  set page(
    paper: "us-letter",
    margin: (top: 1in, bottom: 1in, left: 1in, right: 1in),
    header: context {
      if counter(page).get().first() > 2 [
        #set text(8pt, fill: luma(120))
        #smallcaps[MBFD Mid-Mount Ladder Workgroup — Equipment Evaluation Report]
        #h(1fr)
        #smallcaps[Q1 2026]
        #v(2pt)
        #line(length: 100%, stroke: 0.4pt + luma(200))
      ]
    },
    footer: context {
      if counter(page).get().first() > 1 [
        #line(length: 100%, stroke: 0.4pt + luma(200))
        #v(4pt)
        #set text(8pt, fill: luma(120))
        #smallcaps[Miami Beach Fire Department]
        #h(1fr)
        #counter(page).display("1")
        #h(1fr)
        #smallcaps[Internal Distribution Only]
      ]
    },
  )

  set text(font: "Libertinus Serif", size: 11pt, fill: luma(30))
  set par(leading: 0.7em, justify: true)

  show heading.where(level: 1): it => {
    v(1.2em)
    text(18pt, weight: "bold", fill: rgb("#1a2942"))[#it.body]
    v(0.4em)
    line(length: 100%, stroke: 1.5pt + rgb("#1a2942"))
    v(0.6em)
  }

  show heading.where(level: 2): it => {
    v(0.8em)
    text(14pt, weight: "bold", fill: rgb("#2c4a6e"))[#it.body]
    v(0.3em)
  }

  show heading.where(level: 3): it => {
    v(0.6em)
    text(12pt, weight: "bold", fill: rgb("#3d6590"))[#it.body]
    v(0.2em)
  }

  set table(
    stroke: 0.5pt + luma(180),
    inset: 6pt,
  )

  doc
}
