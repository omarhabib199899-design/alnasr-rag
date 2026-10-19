import {
  ChangeDetectionStrategy,
  Component,
  signal
} from '@angular/core';

import { RouterLink } from '@angular/router';
import { ButtonComponent } from '../../../../../shared/components/button/button.component';

/**
 * Interface for navigation menu items.
 */
interface NavItem {
  label: string;
  fragment: string;
}

@Component({
  selector: 'app-header',
  standalone: true,
  imports: [
    RouterLink,
    ButtonComponent
  ],
  templateUrl: './header.component.html',
  styleUrl: './header.component.scss',
  changeDetection: ChangeDetectionStrategy.OnPush
})
export class HeaderComponent {

  // Signal to track the state of the mobile navigation menu
  readonly menuOpen = signal(false);

  // List of items for the navigation menu
  readonly navItems: NavItem[] = [
    {
      label: 'Home',
      fragment: 'home'
    },
    {
      label: 'Features',
      fragment: 'features'
    },
    {
      label: 'Business Types',
      fragment: 'business'
    },
    {
      label: 'About',
      fragment: 'about'
    },
    {
      label: 'Contact',
      fragment: 'contact'
    }
  ];

  // Toggles the mobile menu open/closed state
  toggleMenu(): void {

    this.menuOpen.update(value => !value);

  }

  // Explicitly closes the mobile menu
  closeMenu(): void {

    this.menuOpen.set(false);

  }

}