import {
  ChangeDetectionStrategy,
  Component,
  signal
} from '@angular/core';

@Component({
  selector: 'app-about-content',
  standalone: true,
  imports: [],
  templateUrl: './about-content.component.html',
  styleUrl: './about-content.component.scss',
  changeDetection: ChangeDetectionStrategy.OnPush
})
export class AboutContentComponent {
  // Badge label for the section header
  readonly badge = signal('About Wekala POS');
  
  // Primary heading for the about section
  readonly title = signal('One System to Manage Your Entire Business');
  
  // Detailed description regarding system capabilities
  readonly descriptionOne = signal('Wekala POS gives businesses a complete solution to manage their daily sales and operational activities — processing orders, managing products, monitoring inventory, tracking customer transactions, running multiple branches, and reviewing performance through clear reports and dashboards.');
  
  // Secondary description highlighting user benefits and flexibility
  readonly descriptionTwo = signal('Simple for employees. Powerful for owners. Flexible enough to support every business type.');

}