import { ChangeDetectionStrategy, Component } from '@angular/core';
import { HeaderComponent } from '../../../shared/components/header/header.component';
import { DevelopedSectionComponent } from '../sections/developedSection/developed-section/developed-section.component';
import { HeroComponent } from '../sections/heroSection/heroComponent/hero.component';
import { AboutSectionComponent } from '../sections/aboutSection/aboutComponent/about-section.component';
import { BusinessSectionComponent } from '../sections/businessSection/business-section/business-section.component';
import { FeaturesSectionComponent } from '../sections/featuresSection/features-section/features-section.component';
import { WhySectionComponent } from '../sections/whySection/why-section/why-section.component';
import { HowWorksSectionComponent } from '../sections/how-worksSection/how-works-section/how-works-section.component';
import { ContactSectionComponent } from '../sections/contactSection/contact-section/contact-section.component';
import { FooterComponent } from '../../../shared/components/footer/footer.component';


@Component({
  selector: 'app-home',
  imports: [HeaderComponent, FooterComponent, HowWorksSectionComponent, FeaturesSectionComponent, WhySectionComponent, BusinessSectionComponent, ContactSectionComponent,DevelopedSectionComponent, HeroComponent, AboutSectionComponent],
  templateUrl: './home.component.html',
  styleUrl: './home.component.scss',
  standalone: true,
  changeDetection: ChangeDetectionStrategy.OnPush,
})
export class HomeComponent {}
