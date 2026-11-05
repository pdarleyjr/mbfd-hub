// @vitest-environment jsdom
import { describe, expect, it } from 'vitest';
import { fireEvent, render, screen } from '@testing-library/react';
import MessageBody, { isLongBody, toParagraphs } from './MessageBody';

describe('MessageBody', () => {
  it('splits paragraphs on blank lines and keeps single line breaks', () => {
    expect(toParagraphs('One\nstill one\n\n\nTwo\n\nThree')).toEqual(['One\nstill one', 'Two', 'Three']);
  });

  it('renders text and http links but never raw HTML', () => {
    const { container } = render(<MessageBody text={'Hi <script>alert(1)</script> see https://example.com/a\n\nSecond'} />);
    expect(container.querySelector('script')).toBeNull();
    expect(container.textContent).toContain('<script>alert(1)</script>');
    expect(container.querySelectorAll('p')).toHaveLength(2);
    expect(container.querySelector('a')?.getAttribute('href')).toBe('https://example.com/a');
  });

  it('collapses long bodies and offers Show full message / Show less', () => {
    const long = 'word '.repeat(200);
    expect(isLongBody(long)).toBe(true);
    render(<MessageBody text={long} />);
    const body = screen.getByTestId('message-body');
    expect(body.getAttribute('data-collapsed')).toBe('true');
    fireEvent.click(screen.getByRole('button', { name: 'Show full message' }));
    expect(body.getAttribute('data-collapsed')).toBe('false');
    expect(screen.getByRole('button', { name: 'Show less' })).toBeTruthy();
  });

  it('does not collapse short bodies', () => {
    render(<MessageBody text="Short note" />);
    expect(screen.queryByTestId('message-body-toggle')).toBeNull();
  });
});
