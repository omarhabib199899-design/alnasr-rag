import {
  ChangeDetectionStrategy,
  Component,
  signal
} from '@angular/core';

/**
 * Interface representing a navigation link within the footer.
 */
interface FooterLink {

  title: string;

  href: string;

}

@Component({
  selector: 'app-footer',
  standalone: true,
  templateUrl: './footer.component.html',
  styleUrl: './footer.component.scss',
  changeDetection: ChangeDetectionStrategy.OnPush
})
export class FooterComponent {

  // Signal holding the list of navigation links displayed in the footer
  readonly links = signal<FooterLink[]>([
    {
      title: 'Home',
      href: '#home'
    },
    {
      title: 'Features',
      href: '#features'
    },
    {
      title: 'Business Types',
      href: '#business'
    },
    {
      title: 'About',
      href: '#about'
    },
    {
      title: 'Contact',
      href: '#contact'
    }
  ]);

}