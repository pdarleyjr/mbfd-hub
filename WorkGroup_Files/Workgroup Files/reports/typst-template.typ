// Modern Professional Typst Template for MBFD Reports
// 2-column layout with 8pt gutters, title breakout

#let project(title: "", subtitle: "", author: "", date: "", body) = {
  // Document metadata
  set document(title: title, author: author)

  // Page setup
  set page(
    paper: "us-letter",
    margin: (x: 0.75in, y: 0.75in),
    header: context {
      if counter(page).get().first() > 1 [
        #set text(8pt, fill: rgb("#666666"))
        #title
        #h(1fr)
        #date
        #line(length: 100%, stroke: 0.5pt + rgb("#cccccc"))
      ]
    },
    footer: [
      #set text(8pt, fill: rgb("#666666"))
      #line(length: 100%, stroke: 0.5pt + rgb("#cccccc"))
      Miami Beach Fire Department — Mid-Mount Ladder Workgroup
      #h(1fr)
      Page #context counter(page).display("1 of 1", both: true)
    ],
  )

  // Typography
  set text(font: "Libertinus Serif", size: 10pt, fill: rgb("#1a1a1a"))
  set par(justify: true, leading: 0.65em)

  // Heading styles
  show heading.where(level: 1): it => {
    set text(font: "Libertinus Sans", size: 14pt, weight: "bold", fill: rgb("#1a365d"))
    place(top + center, scope: "parent", float: true)[
      #block(above: 1.5em, below: 0.8em)[
        #it.body
        #v(4pt)
        #line(length: 100%, stroke: 2pt + rgb("#2b6cb0"))
      ]
    ]
  }

  show heading.where(level: 2): it => {
    set text(font: "Libertinus Sans", size: 12pt, weight: "bold", fill: rgb("#2b6cb0"))
    block(above: 1.2em, below: 0.6em, it)
  }

  show heading.where(level: 3): it => {
    set text(font: "Libertinus Sans", size: 10.5pt, weight: "bold", fill: rgb("#4a5568"))
    block(above: 1em, below: 0.5em, it)
  }

  // Table styling
  set table(
    stroke: 0.5pt + rgb("#e2e8f0"),
    inset: 6pt,
    fill: (x, y) => if y == 0 { rgb("#edf2f7") },
  )

  // Title block — breaks out of columns
  place(top + center, scope: "parent", float: true)[
    #block(width: 100%, inset: (x: 1em, y: 1.5em))[
      #align(center)[
        #text(font: "Libertinus Sans", size: 20pt, weight: "bold", fill: rgb("#1a365d"))[#title]
        #v(8pt)
        #text(font: "Libertinus Sans", size: 12pt, fill: rgb("#4a5568"))[#subtitle]
        #v(6pt)
        #text(size: 10pt, fill: rgb("#718096"))[#author #h(1em) | #h(1em) #date]
        #v(8pt)
        #line(length: 80%, stroke: 2pt + rgb("#2b6cb0"))
      ]
    ]
  ]

  // Body in 2-column layout
  columns(2, gutter: 8pt, body)
}
